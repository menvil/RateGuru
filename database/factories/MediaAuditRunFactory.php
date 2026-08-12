<?php

namespace Database\Factories;

use App\Enums\MediaAuditRunStatus;
use App\Models\MediaAuditRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAuditRun>
 */
class MediaAuditRunFactory extends Factory
{
    protected $model = MediaAuditRun::class;

    public function definition(): array
    {
        $startedAt = now()->subMinutes(5);

        return [
            'started_at' => $startedAt,
            'completed_at' => $startedAt->clone()->addMinutes(2),
            'status' => MediaAuditRunStatus::Completed,
            'assets_checked' => 0,
            'variants_checked' => 0,
            'healthy_assets' => 0,
            'active_unreferenced_assets' => 0,
            'soft_deleted_within_grace' => 0,
            'purgeable_assets' => 0,
            'missing_masters' => 0,
            'missing_variant_files' => 0,
            'physical_orphan_candidates' => 0,
            'failed_media_jobs' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => MediaAuditRunStatus::Running,
            'completed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => MediaAuditRunStatus::Failed,
        ]);
    }
}
