<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateMediaVariantsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 120;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly bool $force = false,
    ) {}

    public function handle(MediaVariantGenerator $generator): void
    {
        $asset = MediaAsset::withTrashed()->find($this->mediaAssetId);

        if ($asset === null) {
            Log::warning('GenerateMediaVariantsJob: media asset not found, skipping.', ['media_asset_id' => $this->mediaAssetId]);

            return;
        }

        try {
            $generator->generateAll($asset, force: $this->force);
        } catch (Throwable $exception) {
            Log::error('GenerateMediaVariantsJob: variant generation failed.', [
                'media_asset_id' => $this->mediaAssetId,
                // Real per-dispatch UUID even under the sync queue (unlike
                // $this->job->getJobId(), which SyncJob hardcodes to '').
                'job_uuid' => $this->job?->uuid(),
                'attempt' => $this->attempts(),
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
