<?php

namespace App\Services\Media\Classification;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Jobs\ClassifyMediaAssetJob;
use App\Models\MediaAsset;

/**
 * Queues a post's media for the configured classifiers once it is ready: a
 * photo when it is stored, a video or GIF when its processing finishes.
 * With no classifier configured, nothing is queued at all.
 */
final class QueueMediaClassification
{
    public function __construct(private readonly MediaClassifierRegistry $registry) {}

    public function ready(MediaAsset $asset): void
    {
        if (! $this->registry->any() || $asset->kind !== MediaKind::PostImage || $asset->status !== MediaStatus::Ready) {
            return;
        }

        // After the commit that made it ready, so the job never finds it
        // otherwise — or never finds it at all.
        ClassifyMediaAssetJob::dispatch((int) $asset->id)->afterCommit();
    }
}
