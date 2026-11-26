<?php

namespace App\Jobs;

use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Services\Media\Classification\ClassificationFrameSampler;
use App\Services\Media\Classification\MediaClassificationFailedException;
use App\Services\Media\Classification\MediaClassificationRecorder;
use App\Services\Media\Classification\MediaClassifierRegistry;
use App\Services\Media\Classification\MediaContentClassifier;
use App\Support\Media\MediaQueue;
use App\Support\Media\WorkerTemporaryDirectory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Shows one post's media to every configured classifier and records each
 * answer (docs/architecture/content-rating.md). It runs on the media queue:
 * a video's frames are ffmpeg runs, and a cloud classifier may take a while
 * over thirty of them.
 *
 * One queued at a time per asset (ShouldBeUniqueUntilProcessing), one
 * running at a time per asset (its run lock), and a classifier that already
 * answered for it at its model version — or failed — is not asked again: an
 * upload, a redelivered video job and media:classify meeting on one asset ask
 * each classifier once, and a paid one is never paid twice. What a
 * classifier fails at is recorded, not retried, on the job's own retry
 * neither; so is media nothing can be taken from. media:classify
 * --retry-failed asks again by clearing those failures, so whichever job
 * runs next for the asset asks. What fails the job is the host — ffmpeg
 * missing, the database — retried once.
 */
final class ClassifyMediaAssetJob implements RunsOnMediaQueue, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** Inside the media connection's retry window (960 s) with a margin. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** How long after it becomes available the media connection could still deliver the job. */
    private const int DELIVERY_WINDOW_SECONDS = 960;

    /**
     * How often a run asks who is still to answer before it ends; anyone left
     * after the last is asked by a job of its own.
     */
    public const int PASSES = 3;

    /** A run its timeout killed left its frames; cleared once past every run's time. */
    private const int STALE_AFTER_SECONDS = 7200;

    /** @param  int  $delaySeconds  how long after its dispatch it becomes available (media:classify spreads them out) */
    public function __construct(
        public readonly int $mediaAssetId,
        public readonly int $delaySeconds = 0,
    ) {
        $this->onConnection(MediaQueue::connection());
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaAssetId;
    }

    /**
     * Held through its delay too, and as long as the media connection could
     * then deliver it — until the job starts. It keeps a second one from
     * being queued meanwhile; it cannot outlast a queue that makes the job
     * wait longer, and need not: the run lock never lets two run at once for
     * one asset, and a job that runs after another finds every answer there
     * and asks no one.
     */
    public function uniqueFor(): int
    {
        return max(0, $this->delaySeconds) + self::DELIVERY_WINDOW_SECONDS;
    }

    /** The lock one run for the asset holds, whatever job it is. */
    public static function runLockKey(int $mediaAssetId): string
    {
        return 'media-classification-run:'.$mediaAssetId;
    }

    public function handle(MediaClassifierRegistry $registry, ClassificationFrameSampler $sampler, MediaClassificationRecorder $recorder): void
    {
        $lock = Cache::lock(self::runLockKey($this->mediaAssetId), $this->timeout + 60);

        // Another run for this asset: this one waits for it, then asks only
        // who is still to answer. Past the wait it leaves the asset to that
        // run, which looks again for who is still to answer before it ends.
        try {
            $lock->block(max(0, (int) config('media.classification.run_wait_seconds')));
        } catch (LockTimeoutException) {
            Log::info('ClassifyMediaAssetJob: another run for the asset outlasted the wait; left to it.', ['media_asset_id' => $this->mediaAssetId]);

            return;
        }

        try {
            $left = $this->classify($registry, $sampler, $recorder);
        } finally {
            $lock->release();
        }

        // Still someone to answer after every pass — failures cleared again
        // and again while it ran: a job of its own asks them, once this run
        // has let the asset go.
        if ($left) {
            self::dispatch($this->mediaAssetId);
        }
    }

    /** @return bool whether a classifier was still to answer when it stopped looking */
    private function classify(MediaClassifierRegistry $registry, ClassificationFrameSampler $sampler, MediaClassificationRecorder $recorder): bool
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        // Gone, not ready yet, or not a post's: nothing to classify.
        if ($asset === null || $asset->status !== MediaStatus::Ready || $asset->kind !== MediaKind::PostImage) {
            return false;
        }

        $pending = self::unanswered($registry, $asset);

        if ($pending === []) {
            return false;
        }

        $directory = self::workDirectory();

        try {
            try {
                $input = $sampler->sample($asset, $directory);
            } catch (MediaClassificationFailedException $nothingToShow) {
                $input = $nothingToShow;
            }

            // Asked until no one is left: a classifier whose failure
            // --retry-failed cleared while this run went on is asked before
            // it ends, from the same frames, so a retry that found the asset
            // held — and left it to this run — is never lost.
            for ($pass = 0; $pending !== [] && $pass < self::PASSES; $pass++) {
                foreach ($pending as $entry) {
                    // The same file would give nothing again: said once, for
                    // each classifier that was to see it.
                    $input instanceof MediaClassificationFailedException
                        ? $recorder->unaskable($asset, $entry['classifier'], $entry['mode'], $input)
                        : $recorder->record($asset, $entry['classifier'], $input, $entry['mode']);
                }

                $pending = self::unanswered($registry, $asset);
            }
        } finally {
            File::deleteDirectory($directory);
        }

        return $pending !== [];
    }

    /**
     * The configured classifiers that have not answered for the asset at
     * their model version. A failure is an answer too, until --retry-failed
     * clears it.
     *
     * @return list<array{classifier: MediaContentClassifier, mode: ClassificationMode}>
     */
    private static function unanswered(MediaClassifierRegistry $registry, MediaAsset $asset): array
    {
        return array_values(array_filter($registry->configured(), fn (array $entry): bool => ! $asset->classifications()
            ->where('classifier', $entry['classifier']->name())
            ->where('model_version', $entry['classifier']->modelVersion())
            ->whereIn('status', [ClassificationStatus::Succeeded, ClassificationStatus::Failed])
            ->exists()));
    }

    /**
     * This run's own directory, inside this worker's (one per user, so two
     * targets on one host never share it): its frames are pictures of an
     * author's media, readable by this worker alone, and gone when the run
     * ends — or, if a timeout killed it first, when a later run clears it.
     */
    private static function workDirectory(): string
    {
        $directory = WorkerTemporaryDirectory::prepare('classification', self::STALE_AFTER_SECONDS).'/'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0o700)) {
            throw new RuntimeException('The classification work directory could not be made.');
        }

        return $directory;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ClassifyMediaAssetJob: classification failed.', [
            'media_asset_id' => $this->mediaAssetId,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }
}
