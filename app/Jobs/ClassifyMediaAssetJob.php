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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * at is recorded, not retried; so is media nothing can be taken from. What
 * fails the job is the host — ffmpeg missing, the database — retried once.
 */
final class ClassifyMediaAssetJob implements RunsOnMediaQueue, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** Inside the media connection's retry window (960 s) with a margin. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** Held as long as the media connection could still deliver the job. */
    public int $uniqueFor = 960;

    /** A run its timeout killed left its frames; cleared once past every run's time. */
    private const int STALE_AFTER_SECONDS = 7200;

    public function __construct(public readonly int $mediaAssetId)
    {
        $this->onConnection(MediaQueue::connection());
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaAssetId;
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
            ->where('status', ClassificationStatus::Succeeded)
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
