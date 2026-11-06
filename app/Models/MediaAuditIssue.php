<?php

namespace App\Models;

use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finding from a single MediaAuditRun. media_asset_id/media_variant_id
 * are plain, unconstrained columns (see the migration) — a historical
 * snapshot, not a live foreign key, so the asset()/variant() relations below
 * can legitimately resolve to null for an old issue whose asset was since
 * purged or whose variant was since regenerated under a new row id.
 *
 * @property MediaAuditIssueType $issue_type
 * @property MediaAuditIssueSeverity $severity
 */
class MediaAuditIssue extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issue_type' => MediaAuditIssueType::class,
            'severity' => MediaAuditIssueSeverity::class,
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<MediaAuditRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MediaAuditRun::class, 'media_audit_run_id');
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /** @return BelongsTo<MediaVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(MediaVariant::class, 'media_variant_id');
    }
}
