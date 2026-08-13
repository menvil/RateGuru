<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Support\Media\PostImagePresenter;
use App\Support\Posts\PostRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property PostStatus $status
 * @property PostStatus|null $deleted_from_status
 * @property-read int $score
 * @property-read string|null $public_image_url
 * @property Carbon|null $created_at
 * @property Carbon|null $published_at
 * @property Carbon|null $deleted_at
 */
class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'deleted_from_status' => PostStatus::class,
            'published_at' => 'datetime',
            'hot_score' => 'float',
            'needs_review' => 'boolean',
            'flagged_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Pending);
    }

    public function scopeHidden(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Hidden);
    }

    public function scopeReported(Builder $query): Builder
    {
        return $query->where('reports_count', '>', 0);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    public function scopeHot(Builder $query): Builder
    {
        return $query->orderByDesc('hot_score');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published);
    }

    /**
     * Statuses an author may delete their own post from. Hidden is a
     * moderation state and deliberately absent: the author must not be able
     * to start the retention purge clock for moderation-hidden evidence.
     * Deleted is never a source state (docs/architecture/post-lifecycle.md).
     */
    public const AUTHOR_DELETABLE_STATUSES = [
        PostStatus::Draft,
        PostStatus::Pending,
        PostStatus::Published,
        PostStatus::Rejected,
    ];

    // Lifecycle helpers. Author deletion = SoftDeletes + status Deleted +
    // deleted_from_status capture; moderation hide = status Hidden with
    // deleted_at null. Public-interaction helpers are trashed-aware so a
    // stale withTrashed() instance can never claim interactivity.

    public function isAuthorDeleted(): bool
    {
        return $this->trashed() && $this->status === PostStatus::Deleted;
    }

    public function canReceiveVotes(): bool
    {
        return ! $this->trashed() && $this->status === PostStatus::Published;
    }

    public function canReceiveComments(): bool
    {
        return ! $this->trashed() && $this->status === PostStatus::Published;
    }

    public function canReceiveReports(): bool
    {
        return ! $this->trashed() && $this->status === PostStatus::Published;
    }

    public function canBeSaved(): bool
    {
        return ! $this->trashed() && $this->status === PostStatus::Published;
    }

    public function canReceiveRatingVotes(): bool
    {
        return ! $this->trashed() && $this->status === PostStatus::Published;
    }

    /**
     * When the author-restore window closes. Null unless author-deleted.
     * Restore is allowed strictly before this instant: at the exact cutoff
     * the window is already expired (retention 0 expires immediately).
     */
    public function authorRestoreDeadline(): ?Carbon
    {
        if (! $this->isAuthorDeleted() || $this->deleted_at === null) {
            return null;
        }

        return $this->deleted_at->copy()->addDays(
            PostRetention::days(),
        );
    }

    public function isAuthorRestorable(): bool
    {
        $deadline = $this->authorRestoreDeadline();

        return $deadline !== null
            && in_array($this->deleted_from_status, self::AUTHOR_DELETABLE_STATUSES, true)
            && now()->lt($deadline);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_asset_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<PostVote, $this> */
    public function postVotes(): HasMany
    {
        return $this->hasMany(PostVote::class);
    }

    /** @return HasMany<PostSave, $this> */
    public function saves(): HasMany
    {
        return $this->hasMany(PostSave::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_saves')->withTimestamps();
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<PostAuthorAnswer, $this> */
    public function authorAnswers(): HasMany
    {
        return $this->hasMany(PostAuthorAnswer::class);
    }

    protected function score(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->upvotes_count ?? 0) - ($this->downvotes_count ?? 0),
        );
    }

    protected function truncatedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->description ? Str::limit($this->description, 140) : null,
        );
    }

    /**
     * Temporary compatibility accessor kept only because Blade views and API
     * resources already call it in many places — removing it would ripple
     * across the presentation layer for no behavioral gain. It carries no
     * filesystem knowledge of its own: it just delegates to
     * PostImagePresenter, which delegates to MediaUrlResolver. New code
     * should prefer injecting PostImagePresenter directly.
     */
    protected function publicImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => app(PostImagePresenter::class)->url($this),
        );
    }
}
