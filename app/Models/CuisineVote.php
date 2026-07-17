<?php

namespace App\Models;

use App\Enums\CuisineType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CuisineType $cuisine */
class CuisineVote extends Model
{
    use HasFactory;

    protected $fillable = ['post_id', 'user_id', 'cuisine'];

    protected function casts(): array
    {
        return [
            'cuisine' => CuisineType::class,
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
