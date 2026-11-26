<?php

namespace App\Jobs;

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Services\Media\Classification\QueueMediaClassification;
use App\Services\Media\VideoAssetProcessor;
use App\Support\Media\MediaQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one video or animated-GIF upload (VideoAssetProcessor) on the
 * media queue: a transcode of a 90-second clip takes minutes, far past the
 * default connection's retry window, so it runs where the window outlasts its
 * timeout. A refused file is final inside the processor; what reaches the
 * queue as a failure is a host problem (storage, a stalled tool), retried
 * once a minute later, then left Failed for the author to upload again. A
 * job that finds another run holding the asset is released to come back.
 *
 * Its post waits as a draft meanwhile; FinalizePostMediaAction publishes it
 * once the media is Ready, or tells the author it Failed.
 */
final class ProcessVideoAssetJob implements RunsOnMediaQueue, ShouldQueue
{
    use Queueable;

    /**
     * Attempts in all, counting the times the job found another run holding
     * the asset and came back later; maxExceptions keeps a real failure to a
     * single retry.
     */
    public int $tries = 5;

    public int $maxExceptions = 2;

    public array $backoff = [60];

    /** How long a job that found the asset busy waits before trying again. */
    public const int BUSY_RETRY_SECONDS = 120;

    /**
     * Inside the media connection's retry window (960 s) with a margin; every
     * ffmpeg run inside has its own, shorter limit.
     */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mediaAssetId)
    {
        $this->onConnection(MediaQueue::connection());
    }

    public function handle(VideoAssetProcessor $processor, FinalizePostMediaAction $finalize, QueueMediaClassification $classification): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset === null) {
            Log::warning('ProcessVideoAssetJob: media asset not found, skipping.', ['media_asset_id' => $this->mediaAssetId]);

            return;
        }

        if (! $processor->process($asset)) {
            // Another run holds the asset. Come back later rather than
            // acknowledge a job whose asset still waits: if that run dies, its
            // lock expires before this one returns.
            $this->release(self::BUSY_RETRY_SECONDS);

            return;
        }

        $asset->refresh();

        // Both are safe to repeat — only a draft is published, and the author
        // hears of each outcome once per asset — so a settled asset always
        // reaches them, also when an earlier run stopped between the two.
        if ($asset->status === MediaStatus::Ready) {
            $finalize->ready($asset);
            $classification->ready($asset);
        } elseif ($asset->status === MediaStatus::Failed) {
            $finalize->failed($asset);
        }
    }

    /**
     * After the last attempt: the asset stops waiting. The reason stays
     * generic — what failed is the host, not anything the author can fix
     * beyond trying again.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessVideoAssetJob: processing failed.', [
            'media_asset_id' => $this->mediaAssetId,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);

        $failed = MediaAsset::query()
            ->whereKey($this->mediaAssetId)
            ->where('status', MediaStatus::Processing)
            ->update(['status' => MediaStatus::Failed, 'processing_error' => 'The video could not be processed.']);

        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset === null) {
            return;
        }

        // No attempt follows this one to retry a notice that could not be
        // written: reported, and left unclaimed for media:sweep-videos.
        if ($failed === 1) {
            try {
                app(FinalizePostMediaAction::class)->failed($asset);
            } catch (Throwable $exception) {
                report($exception);
            }
        } elseif ($asset->status === MediaStatus::Ready) {
            // The media was made and only publishing failed, every time: one
            // more try, so the post does not stay a draft with nothing left
            // to move it.
            try {
                app(FinalizePostMediaAction::class)->ready($asset);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
