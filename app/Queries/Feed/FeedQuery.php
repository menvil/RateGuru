<?php

namespace App\Queries\Feed;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Models\Category;
use App\Models\Follow;
use App\Models\Post;
use App\Support\Database\LikePattern;
use Carbon\CarbonImmutable;
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
        ?FeedLanguageOrder $languages = null,
    ): Builder {
        $query = $this->base()
            ->published()
            ->with(['user.avatarAsset.variants', 'imageAsset.variants', 'tags', 'category']);

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
            $query->whereIn(
                'category_id',
                Category::query()
                    ->active()
                    ->whereIn('slug', $categorySlugs)
                    ->select('id'),
            );
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

        if ($languages !== null) {
            $since = ($languages->since ?? CarbonImmutable::createFromTimestampUTC(0))->utc()->format('Y-m-d H:i:s');

            // One literal with bound values: the viewer's language and own posts while fresh (and, in Hot, above the
            // floor), then fresh English, then the rest — each group then in the sort's own order below. Top passes
            // no window, its groups covering all time; no floor lifts every post.
            $query->orderByRaw(
                'CASE WHEN (posts.language = ? OR posts.user_id = ?) AND posts.published_at >= ? AND posts.hot_score >= ? THEN 0 WHEN posts.language = ? AND posts.published_at >= ? THEN 1 ELSE 2 END',
                [
                    $languages->viewerLanguage,
                    $languages->viewerId ?? 0,
                    $since,
                    $languages->hotFloor ?? -1.0,
                    FeedLanguageOrder::SECOND_LANGUAGE,
                    $since,
                ],
            );
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
        ?FeedLanguageOrder $languages = null,
    ): LengthAwarePaginator {
        return $this->query(
            search: $search,
            tag: $tag,
            sort: $sort,
            category: $category,
            ratingFilters: $ratingFilters,
            followedByUserId: $followedByUserId,
            languages: $languages,
        )
            ->paginate($this->normalizePerPage($perPage));
    }

    /**
     * The hot score at a percentile of the posts published since a moment — what a post has to reach in Hot to be
     * lifted by its language — or null when none was published. Read in PHP, the window holding a couple of days.
     */
    public function hotScoreAt(int $percentile, CarbonImmutable $since): ?float
    {
        $scores = Post::query()->published()->where('published_at', '>=', $since)->pluck('hot_score')->map(fn ($score): float => (float) $score)->sort()->values();

        if ($scores->isEmpty()) {
            return null;
        }

        return $scores[(int) floor(max(0, min(100, $percentile)) / 100 * ($scores->count() - 1))];
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
