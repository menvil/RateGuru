<?php

namespace App\Queries\Comments;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The comments moderation lists: every comment, the ones their authors
 * deleted included — they stay reviewable as moderation and audit history
 * (docs/architecture/comment-lifecycle.md). Each list is one of the tabs
 * below, narrowed by a search over the text, the author's username, the
 * post's title and the comment's id, newest first.
 */
final class ModerationCommentsQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** The lists, in the order the tabs show them. */
    public const TABS = ['all', 'visible', 'hidden', 'deleted', 'reported'];

    public const PER_PAGE = 25;

    /**
     * A page of one list, newest first, with each comment's author and post
     * — a post its author deleted included.
     *
     * @return LengthAwarePaginator<int, Comment>
     */
    public function paginate(string $tab, string $search, int $page): LengthAwarePaginator
    {
        return $this->narrowed($tab, $search)
            ->with([
                'user',
                'post' => fn ($post) => $post->withoutGlobalScope(SoftDeletingScope::class),
            ])
            // Comments that came in at the same moment keep their place across pages.
            ->orderByDesc('comments.created_at')
            ->orderByDesc('comments.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * How many comments each tab lists, before any search.
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

    /** The listed comment with this id, or null when there is none. */
    public function find(int $id): ?Comment
    {
        return $this->listed()->with(['user', 'post' => fn ($post) => $post->withoutGlobalScope(SoftDeletingScope::class)])->find($id);
    }

    /** @return Builder<Comment> */
    private function narrowed(string $tab, string $search): Builder
    {
        $query = $this->onTab($this->listed(), $tab);
        $search = trim($search);

        if ($search !== '') {
            $pattern = LikePattern::containing($search);

            $query->where(function (Builder $matching) use ($search, $pattern): void {
                // “#90412” or “90412” finds the comment with that id as well.
                if (preg_match('/^#?(\d{1,18})$/', $search, $id) === 1) {
                    $matching->orWhere('comments.id', (int) $id[1]);
                }

                $matching
                    ->orWhereRaw("LOWER(comments.body) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereHas('user', fn (Builder $author) => $author->whereRaw("LOWER(username) LIKE LOWER(?) ESCAPE '!'", [$pattern]))
                    ->orWhereHas('post', fn (Builder $post) => $post
                        ->withoutGlobalScope(SoftDeletingScope::class)
                        ->whereRaw("LOWER(title) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            });
        }

        return $query;
    }

    /** @return Builder<Comment> */
    private function listed(): Builder
    {
        return Comment::query()->withoutGlobalScope(SoftDeletingScope::class);
    }

    /**
     * Hidden holds every comment moderation hid, a finalized removal
     * included; Reported, the reported comments whose removal is not final.
     *
     * @param  Builder<Comment>  $query
     * @return Builder<Comment>
     */
    private function onTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'visible' => $query->whereNull('comments.deleted_at')->where('comments.status', CommentStatus::Visible),
            'hidden' => $query->whereNull('comments.deleted_at')->where('comments.status', CommentStatus::Hidden),
            'deleted' => $query->whereNotNull('comments.deleted_at'),
            'reported' => $query->where('comments.reports_count', '>', 0)->whereNull('comments.moderation_removed_at'),
            default => $query,
        };
    }
}
