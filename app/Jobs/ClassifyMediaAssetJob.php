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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
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
 * One at a time per asset (ShouldBeUnique), and a classifier that already
 * answered for it at its model version is not asked again: an upload, a
 * redelivered video job and media:classify meeting on one asset ask each
 * classifier once — a paid one is never paid twice. What a classifier fails
 * at is recorded, not retried — on the job's own retry neither — unless
 * media:classify --retry-failed asks again; so is media nothing can be taken
 * from. What fails the job is the host — ffmpeg missing, the database —
 * retried once.
 */
final class ClassifyMediaAssetJob implements RunsOnMediaQueue, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** Inside the media connection's retry window (960 s) with a margin. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** How long after it becomes available the media connection could still deliver the job. */
    private const int DELIVERY_WINDOW_SECONDS = 960;

    /** A run its timeout killed left its frames; cleared once past every run's time. */
    private const int STALE_AFTER_SECONDS = 7200;

    /**
     * @param  int|null  $retryFailuresBefore  media:classify --retry-failed: a classifier's failure recorded before this moment (a Unix time) is asked again — one this job's own earlier attempt recorded is not
     * @param  int  $delaySeconds  how long after its dispatch it becomes available (media:classify spreads them out)
     */
    public function __construct(
        public readonly int $mediaAssetId,
        public readonly ?int $retryFailuresBefore = null,
        public readonly int $delaySeconds = 0,
    ) {
        $this->onConnection(MediaQueue::connection());
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaAssetId;
    }

    /** Held through its delay too, and as long as the media connection could then deliver it. */
    public function uniqueFor(): int
    {
        return max(0, $this->delaySeconds) + self::DELIVERY_WINDOW_SECONDS;
    }

    public function handle(MediaClassifierRegistry $registry, ClassificationFrameSampler $sampler, MediaClassificationRecorder $recorder): void
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
                    ->when($this->retryFailuresBefore !== null, fn (Builder $recent): Builder => $recent
                        ->where('created_at', '>=', CarbonImmutable::createFromTimestamp((int) $this->retryFailuresBefore)))))
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
