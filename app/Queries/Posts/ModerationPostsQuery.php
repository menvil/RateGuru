<?php

namespace App\Queries\Posts;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Support\Database\LikePattern;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

/**
 * The posts moderation lists: every live post, and the ones their authors
 * deleted that are kept for audit during retention. Only the well-formed
 * author-deleted shape (soft-deleted + status Deleted) is listed among the
 * deleted ones, so a malformed legacy row never shows with the moderation
 * actions of the status it carries (docs/architecture/post-lifecycle.md).
 *
 * Each list is one of the tabs below, narrowed by a search over the title,
 * the author's username and the post's id, and by a category.
 */
final class ModerationPostsQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** The lists, in the order the tabs show them. */
    public const TABS = ['all', 'pending', 'published', 'hidden', 'reported', 'deleted'];

    /** The orders a list can be read in. */
    public const SORTS = ['oldest', 'newest', 'reports'];

    public const PER_PAGE = 25;

    /**
     * A page of one list, with what its rows show: the author and how many
     * posts they have, the category, the image and its variants, and why a
     * rejected post was rejected.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function paginate(string $tab, string $search, ?int $categoryId, string $sort, int $page): LengthAwarePaginator
    {
        $query = $this->narrowed($tab, $search, $categoryId)
            ->with([
                'user' => fn ($user) => $user->withCount('posts'),
                'category',
                'imageAsset.variants',
            ])
            ->addSelect(['posts.*', 'rejection_reason' => ModerationLog::query()
                ->select('reason')
                ->where('target_type', Post::class)
                ->whereColumn('target_id', 'posts.id')
                ->where('action', ModerationActionType::RejectPost)
                ->orderByDesc('id')
                ->limit(1)]);

        return $this->ordered($query, $sort)->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * The ids of one list's page, as paginate() would order it, for selecting
     * every row on the page.
     *
     * @return list<int>
     */
    public function pageIds(string $tab, string $search, ?int $categoryId, string $sort, int $page): array
    {
        return $this->ordered($this->narrowed($tab, $search, $categoryId), $sort)
            ->forPage(max(1, $page), self::PER_PAGE)
            ->pluck('posts.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * How many posts each tab lists, before any search.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->onTab($this->listed(), $tab)->count();
        }

        return $counts;
    }

    /** When the post that has waited longest for review was submitted. */
    public function oldestPendingAt(): ?CarbonInterface
    {
        $oldest = $this->onTab($this->listed(), 'pending')->min('created_at');

        return $oldest !== null ? Carbon::parse($oldest) : null;
    }

    /**
     * The listed posts among these ids, for acting on a selection: a post
     * moderation does not list is never acted on.
     *
     * @param  list<int>  $ids
     * @return Builder<Post>
     */
    public function among(array $ids): Builder
    {
        return $this->listed()->whereIn('posts.id', $ids);
    }

    /**
     * Every order ends on the id, so posts that share a time or a count keep
     * their place from one page to the next.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function ordered(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'newest' => $query->orderByDesc('posts.created_at')->orderByDesc('posts.id'),
            'reports' => $query->orderByDesc('posts.reports_count')->orderBy('posts.created_at')->orderBy('posts.id'),
            default => $query->orderBy('posts.created_at')->orderBy('posts.id'),
        };
    }

    /** @return Builder<Post> */
    private function narrowed(string $tab, string $search, ?int $categoryId): Builder
    {
        $query = $this->onTab($this->listed(), $tab);
        $search = trim($search);

        if ($search !== '') {
            $pattern = LikePattern::containing($search);

            $query->where(function (Builder $matching) use ($search, $pattern): void {
                // “#4821” or “4821” finds the post with that id as well.
                if (preg_match('/^#?(\d{1,18})$/', $search, $id) === 1) {
                    $matching->orWhere('posts.id', (int) $id[1]);
                }

                $matching
                    ->orWhereRaw("LOWER(posts.title) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereHas('user', fn (Builder $author) => $author->whereRaw("LOWER(username) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            });
        }

        if ($categoryId !== null) {
            $query->where('posts.category_id', $categoryId);
        }

        return $query;
    }

    /** @return Builder<Post> */
    private function listed(): Builder
    {
        return Post::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where(fn (Builder $query) => $query
                ->whereNull('posts.deleted_at')
                ->orWhere('posts.status', PostStatus::Deleted));
    }

    /**
     * Reported is the posts the dashboard counts as reported: ones with
     * reports, and ones flagged for review even after their reports were
     * dismissed.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function onTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'pending' => $query->where('posts.status', PostStatus::Pending),
            'published' => $query->where('posts.status', PostStatus::Published),
            'hidden' => $query->where('posts.status', PostStatus::Hidden),
            'reported' => $query->where(fn (Builder $reported) => $reported
                ->where('posts.reports_count', '>', 0)
                ->orWhere('posts.needs_review', true)),
            'deleted' => $query->whereNotNull('posts.deleted_at')->where('posts.status', PostStatus::Deleted),
            default => $query,
        };
    }
}
