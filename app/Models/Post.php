<?php

namespace App\Models;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\PostVote;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'origin_truth' => OriginType::class,
            'cuisine_truth' => CuisineType::class,
            'published_at' => 'datetime',
            'hot_score' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function postVotes(): HasMany
    {
        return $this->hasMany(PostVote::class);
    }

    public function originVotes(): HasMany
    {
        return $this->hasMany(OriginVote::class);
    }

    public function cuisineVotes(): HasMany
    {
        return $this->hasMany(CuisineVote::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
