<?php

namespace App\Queries\Feed;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

final class FeedQuery
{
    public function base(): Builder
    {
        return Post::query();
    }
}
