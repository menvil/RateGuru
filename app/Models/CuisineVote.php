<?php

namespace App\Models;

use App\Enums\CuisineType;
use Illuminate\Database\Eloquent\Model;

class CuisineVote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cuisine' => CuisineType::class,
        ];
    }
}
