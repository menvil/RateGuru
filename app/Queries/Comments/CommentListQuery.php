<?php

namespace App\Queries\Comments;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\CommentStatus;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

final class CommentListQuery implements RawSqlPersistenceBoundary
{
    /** @return Collection<int, Comment> */
    public function get(int $postId, string $sort, int $limit): Collection
    {
        $query = $this->renderableTopLevelForPost($postId)
            ->with([
                'user.avatarAsset.variants',
                'replies' => static function (Relation $relation): void {
                    // The replies relation carries Comment's own SoftDeletes
                    // scope, so author-deleted replies are already excluded;
                    // the status filter drops moderator-hidden ones. Only
                    // surviving public replies ever load.
                    $relation->getQuery()
                        ->where('status', CommentStatus::Visible)
                        ->with('user.avatarAsset.variants')
                        ->oldest()
                        ->orderBy('id');
                },
            ]);

        match ($sort) {
            'top' => $query->orderByRaw('(upvotes_count - downvotes_count) DESC'),
            'hot' => $query->orderByRaw('(upvotes_count + downvotes_count) DESC'),
            default => null,
        };

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The public comment count: visible, non-author-deleted comments only.
     * Structural tombstones are not comments in this count.
     */
    public function countVisible(int $postId): int
    {
        return $this->visibleForPost($postId)->count();
    }

    /**
     * How many top-level rows the thread view can actually render: public
     * roots plus structural tombstone roots that anchor surviving replies.
     * Distinct from countVisible() — a post whose only thread is
     * "deleted root + live reply" has 1 renderable root but a public
     * comment count of 1 (the reply).
     */
    public function countRenderableTopLevel(int $postId): int
    {
        return $this->renderableTopLevelForPost($postId)->count();
    }

    /** @return Builder<Comment> */
    private function visibleForPost(int $postId): Builder
    {
        return Comment::query()
            ->where('post_id', $postId)
            ->where('status', CommentStatus::Visible);
    }

    /**
     * Top-level rows worth rendering: either publicly visible, or removed
     * (author-deleted / moderator-hidden) but still anchoring at least one
     * surviving public reply — those render as neutral structural
     * tombstones so other users' replies never vanish with their parent.
     *
     * withTrashed() is deliberately scoped to this root query alone;
     * nothing here disables SoftDeletes for comment queries in general.
     *
     * @return Builder<Comment>
     */
    private function renderableTopLevelForPost(int $postId): Builder
    {
        return Comment::withTrashed()
            ->where('post_id', $postId)
            ->whereNull('parent_id')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $public): void {
                        $public
                            ->whereNull('deleted_at')
                            ->where('status', CommentStatus::Visible);
                    })
                    ->orWhereHas('replies', function (Builder $reply): void {
                        // replies() re-applies the SoftDeletes scope, so
                        // this counts live, visible replies only.
                        $reply->where('status', CommentStatus::Visible);
                    });
            });
    }
}
