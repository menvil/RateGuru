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
use Illuminate\Pagination\Paginator;

final class FeedQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** A hit has gathered at least this much: 1 + three net votes up. */
    public const HIT_LEAST_GATHERED = 4.0;

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
     * A feed page with a strong post in another language at every so many places (docs/architecture/language-ranking.md):
     * the hits go to places every, 2 × every… in the order given, the rest of the feed — without them — around them.
     *
     * The page is put together by place, not paged by the database: how far into the feed a page starts depends on
     * how many hits came before it. A hit that would sit past the end of a short feed is not placed and stays in
     * the feed where its own order puts it, so no post is shown twice or left out. The total counts both.
     *
     * @param  list<int>  $hitIds  best first (hitCandidates), fixed for as long as the feed is looked through
     */
    public function paginateWithHits(string $sort, FeedLanguageOrder $languages, array $hitIds, int $every, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $this->normalizePerPage($perPage);
        $page = max(1, Paginator::resolveCurrentPage('page'));
        $every = max(2, $every);
        // A hit hidden or deleted since the feed was opened is placed no more.
        $published = Post::query()->published()->whereIn('id', $hitIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $hitIds = array_values(array_filter($hitIds, fn (int $id): bool => in_array($id, $published, true)));

        $withoutHits = $this->query(sort: $sort, languages: $languages)->whereNotIn('posts.id', $hitIds)->reorder()->count();
        // Hit k sits at place k × every, after k × every − k posts of the feed: only as many as the feed can carry.
        $placed = array_slice($hitIds, 0, min(count($hitIds), intdiv($withoutHits, $every - 1)));
        $total = $withoutHits + count($hitIds);

        $start = ($page - 1) * $perPage;
        $before = min(count($placed), intdiv($start, $every));
        $slots = [];

        for ($k = $before + 1; $k <= count($placed) && $k * $every <= $start + $perPage; $k++) {
            $slots[$k * $every - 1] = $placed[$k - 1];
        }

        $feed = $this->query(sort: $sort, languages: $languages)
            ->whereNotIn('posts.id', $placed)
            ->offset($start - $before)
            ->limit($perPage - count($slots))
            ->get();
        $hits = $this->query()->whereIn('posts.id', array_values($slots))->get()->keyBy('id');

        $items = [];
        $next = 0;

        for ($place = $start; $place < $start + $perPage; $place++) {
            $hit = isset($slots[$place]) ? $hits->get($slots[$place]) : null;

            if ($hit instanceof Post) {
                $items[] = $hit;

                continue;
            }

            if ($next < $feed->count()) {
                $items[] = $feed[$next++];
            }
        }

        return new LengthAwarePaginator(new Collection($items), $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']);
    }

    /**
     * The strong posts of the last two days in languages other than the viewer's, best first: those whose votes and
     * comments gathered — 1 + net votes up + half the comments — reach the top percent of the week's posts, and at
     * least three net votes up. Not the hot score: it falls so fast with age that a post with one early vote would
     * outrank a day-old favourite. Read in PHP, a week of posts at a time; the viewer's own posts are never hits.
     *
     * @return list<int>
     */
    public function hitCandidates(string $viewerLanguage, ?int $viewerId, int $topPercent, CarbonImmutable $now, int $limit = 20): array
    {
        $week = Post::query()
            ->published()
            ->where('published_at', '>=', $now->subDays(7))
            ->get(['id', 'user_id', 'language', 'published_at', 'upvotes_count', 'downvotes_count', 'comments_count']);

        if ($week->isEmpty()) {
            return [];
        }

        $gathered = fn (Post $post): float => 1 + max(0, (int) $post->upvotes_count - (int) $post->downvotes_count) + 0.5 * (int) $post->comments_count;
        $scores = $week->map($gathered)->sort()->values();
        $threshold = max(self::HIT_LEAST_GATHERED, $scores[(int) floor((100 - max(0, min(100, $topPercent))) / 100 * ($scores->count() - 1))]);
        $twoDays = $now->subDays(2);

        return $week
            ->filter(fn (Post $post): bool => $post->published_at !== null
                && $post->published_at->greaterThanOrEqualTo($twoDays)
                && $post->language !== $viewerLanguage
                && $post->user_id !== $viewerId
                && $gathered($post) >= $threshold)
            ->sortBy([fn (Post $a, Post $b): int => $gathered($b) <=> $gathered($a), fn (Post $a, Post $b): int => $b->id <=> $a->id])
            ->take($limit)
            ->map(fn (Post $post): int => (int) $post->id)
            ->values()
            ->all();
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
