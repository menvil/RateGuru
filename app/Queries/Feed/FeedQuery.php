<?php

namespace App\Queries\Feed;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
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
        array|string|null $category = null,
        array $ratingFilters = [],
        ?int $followedByUserId = null,
    ): Builder {
        $query = $this->base()
            ->published()
            ->with(['user', 'tags', 'category']);

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

        $categorySlugs = $this->optionKeys($category);

        if ($categorySlugs !== []) {
            $query->whereHas('category', function (Builder $categoryQuery) use ($categorySlugs) {
                $categoryQuery->whereIn('slug', $categorySlugs);
            });
        }

        foreach ($this->normalizeRatingFilters($ratingFilters) as $groupKey => $optionKeys) {
            $query->whereHas('authorAnswers', function (Builder $answerQuery) use ($groupKey, $optionKeys) {
                $answerQuery
                    ->whereHas('group', fn (Builder $groupQuery) => $groupQuery->where('key', $groupKey))
                    ->whereHas('option', fn (Builder $optionQuery) => $optionQuery->whereIn('key', $optionKeys));
            });
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $pattern = LikePattern::containing($term);

            $query->where(function (Builder $searchQuery) use ($pattern) {
                $searchQuery
                    ->whereRaw("LOWER(title) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(description) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
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
        array|string|null $category = null,
        array $ratingFilters = [],
    ): Collection {
        return $this->query(
            search: $search,
            tag: $tag,
            sort: $sort,
            category: $category,
            ratingFilters: $ratingFilters,
        )->get();
    }

    public function paginate(
        ?string $search = null,
        ?string $tag = null,
        string $sort = 'newest',
        ?int $perPage = null,
        array|string|null $category = null,
        array $ratingFilters = [],
        ?int $followedByUserId = null,
    ): LengthAwarePaginator {
        return $this->query(
            search: $search,
            tag: $tag,
            sort: $sort,
            category: $category,
            ratingFilters: $ratingFilters,
            followedByUserId: $followedByUserId,
        )
            ->paginate($this->normalizePerPage($perPage));
    }

    /** @return list<string> */
    private function optionKeys(array|string|null $values): array
    {
        return collect((array) $values)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, list<string>> */
    private function normalizeRatingFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $groupKey => $optionKeys) {
            if (! is_string($groupKey) || trim($groupKey) === '') {
                continue;
            }

            $options = $this->optionKeys(is_array($optionKeys) ? $optionKeys : null);

            if ($options !== []) {
                $normalized[trim($groupKey)] = $options;
            }
        }

        return $normalized;
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
