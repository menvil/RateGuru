<?php

namespace App\Models;

use App\Enums\CuisineType;
use Illuminate\Database\Eloquent\Model;

class CuisineVote extends Model
{
    protected $fillable = ['post_id', 'user_id', 'cuisine'];

    protected function casts(): array
    {
        return [
            'cuisine' => CuisineType::class,
        ];
    }
}
