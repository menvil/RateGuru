<?php

namespace App\Jobs;

use App\Enums\ClassificationStatus;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Services\Media\Classification\ClassificationFrameSampler;
use App\Services\Media\Classification\MediaClassificationRecorder;
use App\Services\Media\Classification\MediaClassifierRegistry;
use App\Support\Media\MediaQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shows one post's media to every configured classifier and records each
 * answer (docs/architecture/content-rating.md). It runs on the media queue:
 * a video's frames are ffmpeg runs, and a cloud classifier may take a while
 * over thirty of them.
 *
 * A classifier that already answered for this asset, at this model version,
 * is not asked again, so a retried or repeated job asks only the rest. What
 * a classifier fails at is recorded, not retried; what fails the job is the
 * host — the file, ffmpeg — retried once, a minute later.
 */
final class ClassifyMediaAssetJob implements RunsOnMediaQueue, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** Inside the media connection's retry window (960 s) with a margin. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mediaAssetId)
    {
        $this->onConnection(MediaQueue::connection());
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
            $input = $sampler->sample($asset, $directory);

            foreach ($pending as $entry) {
                $recorder->record($asset, $entry['classifier'], $input, $entry['mode']);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * A directory of this run's own under the system temp directory: its
     * frames are pictures of an author's media, readable by this worker
     * alone, and gone when the run ends.
     */
    private static function workDirectory(): string
    {
        $root = sys_get_temp_dir().'/rateguru-classification';

        if (! is_dir($root) && ! @mkdir($root, 0o700) && ! is_dir($root)) {
            throw new RuntimeException('The classification work directory could not be made.');
        }

        if (is_link($root) || fileowner($root) !== getmyuid()) {
            throw new RuntimeException('The classification work directory is not this worker\'s own.');
        }

        $directory = $root.'/'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0o700)) {
            throw new RuntimeException('The classification work directory could not be made.');
        }

        return $directory;
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ClassifyMediaAssetJob: classification failed.', [
            'media_asset_id' => $this->mediaAssetId,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }
}
