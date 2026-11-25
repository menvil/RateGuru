<?php

namespace App\Services\Media;

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Settles what video processing leaves behind (media:sweep-videos):
 *
 * - a processed video's original, once it has been kept the retention
 *   period (media.lifecycle.video_original_retention_days);
 * - a failed one's files — original, any master and variants written before
 *   it failed — after the same period. Its row stays: the draft post that
 *   holds it still points at it, and says it failed;
 * - an upload still Processing past the time any job could be working on it
 *   (uploads.videos.processing_window_minutes): its job was lost, so it fails
 *   now and its author is told, rather than waiting for good.
 *
 * Every file goes through MediaStorage; a file already gone is no error.
 */
final class VideoMediaSweeper
{
    private const array VIDEO_TYPES = [MediaType::Video, MediaType::Animation];

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly FinalizePostMediaAction $finalize,
    ) {}

    /** @return int the originals deleted */
    public function deleteExpiredOriginals(): int
    {
        $cutoff = $this->retentionCutoff();
        $deleted = 0;

        MediaAsset::query()
            ->whereIn('media_type', self::VIDEO_TYPES)
            ->where('status', MediaStatus::Ready)
            ->whereNotNull('metadata->original')
            ->where('updated_at', '<=', $cutoff)
            ->chunkById(200, function ($assets) use ($cutoff, &$deleted): void {
                foreach ($assets as $asset) {
                    $original = VideoOriginal::of($asset);
                    $processedAt = self::processedAt($asset);

                    if ($original === null || $processedAt === null || $processedAt->greaterThan($cutoff)) {
                        continue;
                    }

                    if ($this->delete($asset, $original, 'original')) {
                        $this->forgetOriginal($asset);
                        $deleted++;
                    }
                }
            });

        return $deleted;
    }

    /** @return int the failed uploads whose files were removed */
    public function removeFailedFiles(): int
    {
        $removed = 0;

        MediaAsset::query()
            ->whereIn('media_type', self::VIDEO_TYPES)
            ->where('status', MediaStatus::Failed)
            ->whereNull('metadata->files_removed_at')
            ->where('updated_at', '<=', $this->retentionCutoff())
            ->with('variants')
            ->chunkById(200, function ($assets) use (&$removed): void {
                foreach ($assets as $asset) {
                    $locations = [new MediaLocation($asset->disk, $asset->path)];
                    $original = VideoOriginal::of($asset);

                    if ($original !== null) {
                        $locations[] = $original;
                    }

                    foreach ($asset->variants as $variant) {
                        $locations[] = new MediaLocation($variant->disk, $variant->path);
                    }

                    $all = array_reduce($locations, fn (bool $carry, MediaLocation $location): bool => $this->delete($asset, $location, 'failed_file') && $carry, true);

                    if (! $all) {
                        continue;
                    }

                    $asset->variants()->delete();
                    $asset->forceFill(['metadata' => [...($asset->metadata ?? []), 'files_removed_at' => now()->toIso8601ZuluString()]])->save();
                    $removed++;
                }
            });

        return $removed;
    }

    /** @return int the uploads failed for having waited past every retry */
    public function failStuck(): int
    {
        $failed = 0;

        MediaAsset::query()
            ->whereIn('media_type', self::VIDEO_TYPES)
            ->where('status', MediaStatus::Processing)
            ->where('created_at', '<=', now()->subMinutes((int) config('uploads.videos.processing_window_minutes')))
            ->chunkById(200, function ($assets) use (&$failed): void {
                foreach ($assets as $asset) {
                    // The processor's own lock: a run working on it now is
                    // left to finish.
                    $lock = $this->processingLock($asset);

                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        $changed = MediaAsset::query()
                            ->whereKey($asset->id)
                            ->where('status', MediaStatus::Processing)
                            ->update(['status' => MediaStatus::Failed, 'processing_error' => 'The video was not processed in time.']);
                    } finally {
                        $lock->release();
                    }

                    if ($changed === 1) {
                        Log::warning('VideoMediaSweeper: video upload never processed; failed it.', ['media_asset_id' => $asset->id]);
                        $this->finalize->failed($asset->refresh());
                        $failed++;
                    }
                }
            });

        return $failed;
    }

    private function retentionCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays((int) config('media.lifecycle.video_original_retention_days'));
    }

    /** When it was processed; its last update for a row from before that was recorded. */
    private static function processedAt(MediaAsset $asset): ?CarbonImmutable
    {
        $recorded = ($asset->metadata ?? [])['processed_at'] ?? null;

        if (is_string($recorded)) {
            try {
                return CarbonImmutable::parse($recorded);
            } catch (Throwable) {
                return null;
            }
        }

        return $asset->updated_at === null ? null : CarbonImmutable::instance($asset->updated_at);
    }

    private function delete(MediaAsset $asset, MediaLocation $location, string $what): bool
    {
        try {
            $this->storage->deleteIfExists($location);

            return true;
        } catch (Throwable $exception) {
            Log::error('VideoMediaSweeper: file delete failed.', [
                'media_asset_id' => $asset->id,
                'disk' => $location->disk,
                'path' => $location->path,
                'operation' => $what,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function forgetOriginal(MediaAsset $asset): void
    {
        $metadata = $asset->metadata ?? [];
        unset($metadata['original']);

        $asset->forceFill(['metadata' => [...$metadata, 'original_deleted_at' => now()->toIso8601ZuluString()]])->save();
    }

    private function processingLock(MediaAsset $asset): Lock
    {
        $store = Cache::store('database')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The "database" cache store does not support locks.');
        }

        return $store->lock("media-video-process:{$asset->id}", VideoAssetProcessor::LOCK_SECONDS);
    }
}
