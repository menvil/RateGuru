<?php

namespace App\Queries\Feed;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class FeedQuery
{
    public function base(): Builder
    {
        return Post::query();
    }

    public function query(): Builder
    {
        return $this->base()->published();
    }

    public function get(): Collection
    {
        return $this->query()->get();
    }
}
