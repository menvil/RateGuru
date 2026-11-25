<?php

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\MediaAsset;
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
 * once a minute later, then left Failed for the author to upload again.
 */
final class ProcessVideoAssetJob implements RunsOnMediaQueue, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

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

    public function handle(VideoAssetProcessor $processor): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset === null) {
            Log::warning('ProcessVideoAssetJob: media asset not found, skipping.', ['media_asset_id' => $this->mediaAssetId]);

            return;
        }

        $processor->process($asset);
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

        MediaAsset::query()
            ->whereKey($this->mediaAssetId)
            ->where('status', MediaStatus::Processing)
            ->update(['status' => MediaStatus::Failed, 'processing_error' => 'The video could not be processed.']);
    }
}
