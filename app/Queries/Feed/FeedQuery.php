<?php

namespace App\Queries\Feed;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        ?string $origin = null,
        ?string $cuisine = null,
    ): Builder {
        $query = $this->base()
            ->published()
            ->with(['user', 'tags']);

        if ($tag !== null && $tag !== '') {
            $query->whereHas('tags', function (Builder $tagQuery) use ($tag) {
                $tagQuery->where('slug', $tag);
            });
        }

        $originType = $origin !== null ? OriginType::tryFrom($origin) : null;

        if ($originType !== null && $originType !== OriginType::Unknown) {
            $query->where('origin_truth', $originType);
        }

        $cuisineType = $cuisine !== null ? CuisineType::tryFrom($cuisine) : null;

        if ($cuisineType !== null && $cuisineType !== CuisineType::Unknown) {
            $query->where('cuisine_truth', $cuisineType);
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
        ?string $origin = null,
        ?string $cuisine = null,
    ): Collection {
        return $this->query($search, $tag, $sort, $origin, $cuisine)->get();
    }

    public function paginate(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
        ?int $perPage = null,
        ?string $origin = null,
        ?string $cuisine = null,
    ): LengthAwarePaginator {
        return $this->query(search: $search, tag: $tag, sort: $sort, origin: $origin, cuisine: $cuisine)
            ->paginate($this->normalizePerPage($perPage));
    }

    private function normalizePerPage(?int $perPage): int
    {
        $default = (int) config('feed.default_per_page', 12);
        $max = (int) config('feed.max_per_page', 50);

        if ($perPage === null || $perPage < 1) {
            return max(1, min($default, $max));
        }

        return max(1, min($perPage, $max));
    }
}
