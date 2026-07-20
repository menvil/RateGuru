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

    public function canReceiveVotes(): bool
    {
        return $this->status === CommentStatus::Visible;
    }

    protected function score(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->upvotes_count ?? 0) - ($this->downvotes_count ?? 0),
        );
    }
}
