<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CommentStatus $status
 * @property-read int $score
 */
class Comment extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<CommentVote, $this> */
    public function commentVotes(): HasMany
    {
        return $this->hasMany(CommentVote::class);
    }

    // Lifecycle presentation helpers (docs/architecture/comment-lifecycle.md).
    // Author deletion and moderation hide are orthogonal states expressed by
    // existing storage: deleted_at = the author removed it, status Hidden =
    // moderation removed it. These helpers are the single place Blade,
    // components and queries derive tombstone semantics from.

    public function isAuthorDeleted(): bool
    {
        return $this->trashed();
    }

    public function isModeratorHidden(): bool
    {
        return ! $this->trashed() && $this->status === CommentStatus::Hidden;
    }

    /**
     * A structural tombstone: publicly removed content whose row must still
     * render as a neutral placeholder when surviving replies need their
     * thread anchor. Author deletion wins over a pre-existing hide.
     */
    public function isStructuralTombstone(): bool
    {
        return $this->isAuthorDeleted() || $this->isModeratorHidden();
    }

    public function canReceiveVotes(): bool
    {
        // Explicitly trashed-aware: a withTrashed() structural instance may
        // still carry status Visible, but an author-deleted comment can
        // never receive votes again.
        return ! $this->trashed() && $this->status === CommentStatus::Visible;
    }

    protected function score(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->upvotes_count ?? 0) - ($this->downvotes_count ?? 0),
        );
    }
}
