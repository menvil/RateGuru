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

    public function query(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
    ): Builder {
        $query = $this->base()->published();

        if ($tag !== null && $tag !== '') {
            $query->whereHas('tags', function (Builder $tagQuery) use ($tag) {
                $tagQuery->where('slug', $tag);
            });
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);

            $query->where(function (Builder $searchQuery) use ($term) {
                $searchQuery
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        return match ($sort) {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('created_at'),
            'top' => $query->orderByRaw('(upvotes_count - downvotes_count) DESC')->orderByDesc('published_at'),
            'hot' => $query->orderByDesc('hot_score')->orderByDesc('published_at'),
            default => $query->orderByDesc('published_at')->orderByDesc('created_at'),
        };
    }

    public function get(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
    ): Collection {
        return $this->query($search, $tag, $sort)->get();
    }
}
