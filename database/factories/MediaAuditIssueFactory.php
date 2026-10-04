<?php

namespace Database\Factories;

use App\Enums\MediaAuditIssueType;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAuditIssue>
 */
class MediaAuditIssueFactory extends Factory
{
    protected $model = MediaAuditIssue::class;

    public function definition(): array
    {
        $type = MediaAuditIssueType::MissingMasterFile;

        return [
            'media_audit_run_id' => MediaAuditRun::factory(),
            'issue_type' => $type,
            'severity' => $type->severity(),
            'media_asset_id' => null,
            'media_variant_id' => null,
            'disk' => null,
            'path' => null,
            'context' => null,
        ];
    }

    public function ofType(MediaAuditIssueType $type): static
    {
        return $this->state(fn (): array => [
            'issue_type' => $type,
            'severity' => $type->severity(),
        ]);
    }
}
