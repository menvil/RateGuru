<?php

namespace App\Models;

use App\Enums\PostStatus;
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
 * @property-read int $score
 * @property-read string|null $public_image_url
 * @property string|null $thumbnail_url
 * @property Carbon|null $created_at
 * @property Carbon|null $published_at
 */
class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
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

    public function canReceiveVotes(): bool
    {
        return $this->status === PostStatus::Published;
    }

    public function canReceiveComments(): bool
    {
        return $this->status === PostStatus::Published;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @return BelongsTo<RatingOption, $this> */
    public function categoryOption(): BelongsTo
    {
        return $this->belongsTo(RatingOption::class, 'category_option_id');
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

    protected function publicImageUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $path = trim((string) $this->image_path);

                if ($path !== '') {
                    return '/storage/'.ltrim($path, '/');
                }

                $url = trim((string) $this->image_url);

                return $url !== '' ? $url : null;
            },
        );
    }
}
