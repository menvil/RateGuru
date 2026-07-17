<?php

namespace App\Queries\Feed;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Models\Follow;
use App\Models\Post;
use App\Support\Database\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class FeedQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** @return Builder<Post> */
    public function base(): Builder
    {
        return Post::query();
    }

    /** @return Builder<Post> */
    public function query(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
        array|string|null $origin = null,
        array|string|null $cuisine = null,
        ?int $followedByUserId = null,
    ): Builder {
        $query = $this->base()
            ->published()
            ->with(['user', 'tags']);

        if ($followedByUserId !== null) {
            $query->whereIn(
                'user_id',
                Follow::query()
                    ->where('follower_id', $followedByUserId)
                    ->select('author_id'),
            );
        }

        if ($tag !== null && $tag !== '') {
            $query->whereHas('tags', function (Builder $tagQuery) use ($tag) {
                $tagQuery->where('slug', $tag);
            });
        }

        $originTypes = $this->originTypes($origin);
        $categoryOptionKeys = $this->categoryOptionKeys($origin);

        // The origin filter accepts both legacy OriginType values (old posts store
        // them in origin_truth) and rating-option keys of the sidebar category
        // group (the author-chosen posts.category_option_id) — sidebar "Categories"
        // links pass the latter.
        if ($originTypes !== [] || $categoryOptionKeys !== []) {
            $query->where(function (Builder $originQuery) use ($originTypes, $categoryOptionKeys) {
                if ($originTypes !== []) {
                    $originQuery->whereIn('origin_truth', $originTypes);
                }

                if ($categoryOptionKeys !== []) {
                    $originQuery->orWhereHas('categoryOption', function (Builder $optionQuery) use ($categoryOptionKeys) {
                        $optionQuery->whereIn('key', $categoryOptionKeys);
                    });
                }
            });
        }

        $cuisineTypes = $this->cuisineTypes($cuisine);

        if ($cuisineTypes !== []) {
            $query->whereIn('cuisine_truth', $cuisineTypes);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $pattern = LikePattern::containing($term);

            $query->where(function (Builder $searchQuery) use ($pattern) {
                $searchQuery
                    ->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return match ($sort) {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('created_at')->orderByDesc('id'),
            'top' => $query->orderByRaw('(upvotes_count - downvotes_count) DESC')->orderByDesc('published_at')->orderByDesc('id'),
            'hot' => $query->orderByDesc('hot_score')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    public function get(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
        array|string|null $origin = null,
        array|string|null $cuisine = null,
    ): Collection {
        return $this->query($search, $tag, $sort, $origin, $cuisine)->get();
    }

    public function paginate(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
        ?int $perPage = null,
        array|string|null $origin = null,
        array|string|null $cuisine = null,
        ?int $followedByUserId = null,
    ): LengthAwarePaginator {
        return $this->query(search: $search, tag: $tag, sort: $sort, origin: $origin, cuisine: $cuisine, followedByUserId: $followedByUserId)
            ->paginate($this->normalizePerPage($perPage));
    }

    /**
     * @return list<OriginType>
     */
    private function originTypes(array|string|null $origin): array
    {
        return collect((array) $origin)
            ->map(fn ($value): ?OriginType => is_string($value) ? OriginType::tryFrom($value) : null)
            ->filter(fn (?OriginType $type): bool => $type !== null && $type !== OriginType::Unknown)
            ->unique(fn (OriginType $type): string => $type->value)
            ->values()
            ->all();
    }

    /**
     * Every origin filter value doubles as a rating-option key for the
     * author-chosen post category (legacy enum values included — preset option
     * keys like 'homemade' overlap with them).
     *
     * @return list<string>
     */
    private function categoryOptionKeys(array|string|null $origin): array
    {
        return collect((array) $origin)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<CuisineType>
     */
    private function cuisineTypes(array|string|null $cuisine): array
    {
        return collect((array) $cuisine)
            ->map(fn ($value): ?CuisineType => is_string($value) ? CuisineType::tryFrom($value) : null)
            ->filter(fn (?CuisineType $type): bool => $type !== null && $type !== CuisineType::Unknown)
            ->unique(fn (CuisineType $type): string => $type->value)
            ->values()
            ->all();
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
