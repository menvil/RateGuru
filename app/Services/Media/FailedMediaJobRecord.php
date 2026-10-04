<?php

namespace App\Services\Media;

use Illuminate\Support\Carbon;

/**
 * One row from `failed_jobs`, safely parsed — never the unserialized job
 * object itself. See FailedMediaJobReader.
 */
final readonly class FailedMediaJobRecord
{
    public function __construct(
        public string $uuid,
        public string $jobClass,
        public ?int $mediaAssetId,
        public Carbon $failedAt,
        public string $exceptionSummary,
    ) {}
}
