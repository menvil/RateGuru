<?php

namespace App\Services\Media\Classification;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Jobs\ClassifyMediaAssetJob;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Queues a post's media for the configured classifiers once it is ready: a
 * photo when it is stored, a video or GIF when its processing finishes.
 * With no classifier configured, nothing is queued at all.
 *
 * Never at the upload's expense: the job is queued after the commit that
 * made the media ready — so it never finds it otherwise, or not at all — and
 * a queue that will not take it is reported, never thrown back into the
 * upload that has already committed.
 */
final class QueueMediaClassification
{
    public function __construct(private readonly MediaClassifierRegistry $registry) {}

    public function ready(MediaAsset $asset): void
    {
        if (! $this->registry->any() || $asset->kind !== MediaKind::PostImage || $asset->status !== MediaStatus::Ready) {
            return;
        }

        $id = (int) $asset->id;

        DB::afterCommit(function () use ($id): void {
            try {
                // Dispatched when the pending dispatch goes, here: inside the
                // try, and through the unique lock the job holds per asset.
                $pending = ClassifyMediaAssetJob::dispatch($id);
                unset($pending);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
