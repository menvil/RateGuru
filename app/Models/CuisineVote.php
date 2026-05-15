<?php

namespace App\Models;

use App\Enums\CuisineType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
