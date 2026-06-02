<?php

namespace App\Support\Cache;

use App\Models\Post;
use Closure;

final class PostListCacheManager
{
    public function remember(string $key, Closure $callback): mixed
    {
        return $callback();
    }

    public function invalidateForPost(Post $post): void
    {
        // Placeholder: real invalidation will be implemented when feed caching is enabled.
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function keyForFeed(array $filters): string
    {
        ksort($filters);

        $parts = collect($filters)
            ->map(fn ($value, string $key): string => $key.'='.(string) $value)
            ->implode(':');

        return 'post-list:feed:'.$parts;
    }
}
