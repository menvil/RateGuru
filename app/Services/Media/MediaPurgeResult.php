<?php

namespace App\Services\Media;

use App\Enums\MediaPurgeOutcome;
use Throwable;

/**
 * The typed outcome of one MediaLifecycleService::purge() call — gives
 * callers (the media:purge command, tests) something to branch/assert on
 * instead of parsing log strings.
 */
final readonly class MediaPurgeResult
{
    public function __construct(
        public int $assetId,
        public MediaPurgeOutcome $outcome,
        public ?Throwable $exception = null,
    ) {}

    public static function purged(int $assetId): self
    {
        return new self($assetId, MediaPurgeOutcome::Purged);
    }

    public static function skipped(int $assetId, MediaPurgeOutcome $outcome): self
    {
        return new self($assetId, $outcome);
    }

    public static function failed(int $assetId, Throwable $exception): self
    {
        return new self($assetId, MediaPurgeOutcome::Failed, $exception);
    }
}
