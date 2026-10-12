<?php

declare(strict_types=1);

namespace App\Http\Controllers\ArchitectureFixtures;

use App\Models\Post;

final class ControllerQueryBoundary
{
    public function show(Post $post, ReadOnlyPostLookup $query): int
    {
        return $query->identifier($post).(int) $post->getKey();
    }
}

final class ReadOnlyPostLookup
{
    public function identifier(Post $post): int
    {
        return (int) $post->getKey();
    }
}
