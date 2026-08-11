<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class GenerateMediaVariantsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 120;

    public function __construct(public readonly int $mediaAssetId) {}

    public function handle(MediaVariantGenerator $generator): void
    {
        $asset = MediaAsset::withTrashed()->find($this->mediaAssetId);

        if ($asset === null) {
            Log::warning('GenerateMediaVariantsJob: media asset not found, skipping.', ['media_asset_id' => $this->mediaAssetId]);

            return;
        }

        $generator->generateAll($asset);
    }
}
