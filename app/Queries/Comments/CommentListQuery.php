<?php

namespace App\Queries\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

final class CommentListQuery
{
    /** @return Collection<int, Comment> */
    public function get(int $postId, string $sort, int $limit): Collection
    {
        $query = $this->visibleForPost($postId)
            ->whereNull('parent_id')
            ->with([
                'user',
                'replies' => static function (Relation $relation): void {
                    $relation->getQuery()
                        ->where('status', CommentStatus::Visible)
                        ->with('user')
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

    public function countVisible(int $postId): int
    {
        return $this->visibleForPost($postId)->count();
    }

    public function countVisibleTopLevel(int $postId): int
    {
        return $this->visibleForPost($postId)
            ->whereNull('parent_id')
            ->count();
    }

    /** @return Builder<Comment> */
    private function visibleForPost(int $postId): Builder
    {
        return Comment::query()
            ->where('post_id', $postId)
            ->where('status', CommentStatus::Visible);
    }
}
