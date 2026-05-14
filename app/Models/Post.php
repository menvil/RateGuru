<?php

namespace App\Models;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use Illuminate\Database\Eloquent\Model;
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
}
