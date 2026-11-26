<?php

namespace App\Services\Media;

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\Media\MediaAssetMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Settles what video processing leaves behind (media:sweep-videos):
 *
 * - a processed video's original, once it has been kept the retention
 *   period (media.lifecycle.video_original_retention_days);
 * - a failed one's files — original, any master and variants written before
 *   it failed — after the same period. Its row stays: the draft post that
 *   holds it still points at it, and says it failed;
 * - an upload still Processing a day after it arrived
 *   (media.lifecycle.video_stuck_after_hours), far past every retry: its job
 *   was lost, so it fails now and its author is told, rather than waiting
 *   for good;
 * - an outcome its author was not told of — the notice could not be
 *   written on the job's last attempt, when no attempt follows to retry it —
 *   within UNTOLD_WITHIN_HOURS of it. A ready video's draft that never got
 *   published is published on the way.
 *
 * Every file goes through MediaStorage; a file already gone is no error.
 */
final class VideoMediaSweeper
{
    private const array VIDEO_TYPES = [MediaType::Video, MediaType::Animation];

    /** How far back an untold outcome is still told: news, not history. */
    public const int UNTOLD_WITHIN_HOURS = 48;

    private int $failures = 0;

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
            // An ISO-8601 UTC string compares as its time does.
            ->where('metadata->processed_at', '<=', $cutoff->toIso8601ZuluString())
            ->chunkById(200, function ($assets) use (&$deleted): void {
                foreach ($assets as $asset) {
                    $original = VideoOriginal::of($asset);

                    if ($original === null) {
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
                    MediaAssetMetadata::change((int) $asset->id, fn (array $metadata): array => [...$metadata, 'files_removed_at' => now()->toIso8601ZuluString()]);
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
            ->videosProcessingSince(now()->subHours((int) config('media.lifecycle.video_stuck_after_hours')))
            ->chunkById(200, function ($assets) use (&$failed): void {
                foreach ($assets as $asset) {
                    // The processor's own lock: a run working on it now is
                    // left to finish.
                    $lock = VideoAssetProcessor::lockFor($asset->id);

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
                        $failed++;

                        // A notice that could not be written leaves its claim
                        // free, and the sweep goes on to the next upload.
                        try {
                            $this->finalize->failed($asset->refresh());
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }
                }
            });

        return $failed;
    }

    /**
     * Tells the authors of what their uploads came to when the job could
     * not, once per asset: each notice is claimed on its asset, so one the
     * job sent meanwhile is never sent again.
     *
     * @return int the assets whose outcome was settled again
     */
    public function tellUntold(): int
    {
        $told = 0;

        MediaAsset::query()
            ->whereIn('media_type', self::VIDEO_TYPES)
            ->where('updated_at', '>=', now()->subHours(self::UNTOLD_WITHIN_HOURS))
            ->where(fn ($query) => $query
                ->where(fn ($failed) => $failed->where('status', MediaStatus::Failed)->whereNull('metadata->failure_notified_at'))
                ->orWhere(fn ($ready) => $ready->where('status', MediaStatus::Ready)->whereNull('metadata->ready_notified_at')))
            ->whereIn('id', Post::query()->select('image_asset_id')->whereIn('status', [PostStatus::Draft, PostStatus::Published, PostStatus::Pending]))
            ->chunkById(200, function ($assets) use (&$told): void {
                foreach ($assets as $asset) {
                    try {
                        $asset->status === MediaStatus::Ready ? $this->finalize->ready($asset) : $this->finalize->failed($asset);
                        $told++;
                    } catch (Throwable $exception) {
                        // Still unclaimed: the next sweep tries again.
                        report($exception);
                    }
                }
            });

        return $told;
    }

    private function retentionCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays((int) config('media.lifecycle.video_original_retention_days'));
    }

    /** Deletes that failed since this sweeper was made: the command fails loudly on any. */
    public function failures(): int
    {
        return $this->failures;
    }

    private function delete(MediaAsset $asset, MediaLocation $location, string $what): bool
    {
        try {
            $this->storage->deleteIfExists($location);

            return true;
        } catch (Throwable $exception) {
            $this->failures++;
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
        MediaAssetMetadata::change((int) $asset->id, function (array $metadata): array {
            unset($metadata['original']);

            return [...$metadata, 'original_deleted_at' => now()->toIso8601ZuluString()];
        });
    }
}
