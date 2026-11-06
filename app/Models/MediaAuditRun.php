<?php

namespace App\Models;

use App\Enums\MediaAuditRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A persisted snapshot of one full media audit sweep (MediaAuditService),
 * run asynchronously via RunMediaAuditJob — never computed inline on a
 * Filament page request. See docs/architecture/media.md.
 *
 * @property MediaAuditRunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property-read int $issues_count only set when the query eager-loads it via withCount('issues')
 */
class MediaAuditRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MediaAuditRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'assets_checked' => 'integer',
            'variants_checked' => 'integer',
            'healthy_assets' => 'integer',
            'active_unreferenced_assets' => 'integer',
            'soft_deleted_within_grace' => 'integer',
            'purgeable_assets' => 'integer',
            'missing_masters' => 'integer',
            'missing_variant_files' => 'integer',
            'physical_orphan_candidates' => 'integer',
            'failed_media_jobs' => 'integer',
        ];
    }

    /** @return HasMany<MediaAuditIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(MediaAuditIssue::class);
    }
}
