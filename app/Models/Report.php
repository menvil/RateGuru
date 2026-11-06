<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property ReportReason $reason
 * @property ReportStatus $status
 * @property Carbon|null $resolved_at
 */
class Report extends Model
{
    use HasFactory;

    protected $fillable = ['reporter_id', 'target_type', 'target_id', 'reason', 'message', 'status', 'resolved_by', 'resolved_at', 'resolution_note'];

    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * The moderator who resolved or ignored the report, while it is closed.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Polymorphic relation to the reported content (Post, Comment, or User).
     * Returns null when the target row has been deleted; callers must
     * tolerate that.
     *
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }
}
