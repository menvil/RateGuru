<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

final class EloquentQueries
{
    public function posts(Post $post): Builder
    {
        Post::query()->where('status', 'published')->exists();

        return $post->comments()->where('status', 'visible')->getQuery();
    }
}
