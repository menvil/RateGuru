<?php

namespace App\Jobs;

use App\Enums\ClassificationStatus;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Services\Media\Classification\ClassificationFrameSampler;
use App\Services\Media\Classification\MediaClassificationFailedException;
use App\Services\Media\Classification\MediaClassificationRecorder;
use App\Services\Media\Classification\MediaClassifierRegistry;
use App\Support\Media\MediaQueue;
use App\Support\Media\WorkerTemporaryDirectory;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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
 * answered for it at its model version is not asked again: an upload, a
 * redelivered video job and media:classify meeting on one asset ask each
 * classifier once — a paid one is never paid twice. What a classifier fails
 * at is recorded, not retried — on the job's own retry neither — unless
 * media:classify --retry-failed asks again; so is media nothing can be taken
 * from. What fails the job is the host — ffmpeg missing, the database —
 * retried once.
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

    /** How soon a retry that found another run for its asset comes back. */
    private const int BUSY_RETRY_SECONDS = 60;

    /** A run its timeout killed left its frames; cleared once past every run's time. */
    private const int STALE_AFTER_SECONDS = 7200;

    /**
     * @param  int|null  $retryFailuresThrough  media:classify --retry-failed: a classifier's failure recorded up to this answer id — the last there was when the command ran — is asked again; one recorded since, by this job's own earlier attempt among others, is not
     * @param  int  $delaySeconds  how long after its dispatch it becomes available (media:classify spreads them out)
     */
    public function __construct(
        public readonly int $mediaAssetId,
        public readonly ?int $retryFailuresThrough = null,
        public readonly int $delaySeconds = 0,
    ) {
        $this->onConnection(MediaQueue::connection());
    }

    /** A retry of failures is queued apart: a plain job waiting for the asset never keeps it out. */
    public function uniqueId(): string
    {
        return $this->mediaAssetId.($this->retryFailuresThrough === null ? '' : ':retry');
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

        // Another run for this asset: it asks every classifier still to
        // answer, so a plain job has nothing left to do. A retry of failures
        // — which that run skips — comes back after it, never lost.
        if (! $lock->get()) {
            if ($this->retryFailuresThrough !== null) {
                self::dispatch($this->mediaAssetId, $this->retryFailuresThrough, self::BUSY_RETRY_SECONDS)
                    ->delay(now()->addSeconds(self::BUSY_RETRY_SECONDS));
            }

            return;
        }

        try {
            $this->classify($registry, $sampler, $recorder);
        } finally {
            $lock->release();
        }
    }

    private function classify(MediaClassifierRegistry $registry, ClassificationFrameSampler $sampler, MediaClassificationRecorder $recorder): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        // Gone, not ready yet, or not a post's: nothing to classify.
        if ($asset === null || $asset->status !== MediaStatus::Ready || $asset->kind !== MediaKind::PostImage) {
            return;
        }

        $pending = array_values(array_filter($registry->configured(), fn (array $entry): bool => ! $asset->classifications()
            ->where('classifier', $entry['classifier']->name())
            ->where('model_version', $entry['classifier']->modelVersion())
            ->where(fn (Builder $answer): Builder => $answer
                ->where('status', ClassificationStatus::Succeeded)
                // A failure is an answer too, unless --retry-failed asks
                // again for one recorded before it was asked.
                ->orWhere(fn (Builder $failure): Builder => $failure
                    ->where('status', ClassificationStatus::Failed)
                    ->when($this->retryFailuresThrough !== null, fn (Builder $recent): Builder => $recent
                        ->where('id', '>', (int) $this->retryFailuresThrough))))
            ->exists()));

        if ($pending === []) {
            return;
        }

        $directory = self::workDirectory();

        try {
            try {
                $input = $sampler->sample($asset, $directory);
            } catch (MediaClassificationFailedException $nothingToShow) {
                // The same file would give nothing again: said once, for
                // each classifier that was to see it.
                foreach ($pending as $entry) {
                    $recorder->unaskable($asset, $entry['classifier'], $entry['mode'], $nothingToShow);
                }

                return;
            }

            foreach ($pending as $entry) {
                $recorder->record($asset, $entry['classifier'], $input, $entry['mode']);
            }
        } finally {
            File::deleteDirectory($directory);
        }
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
