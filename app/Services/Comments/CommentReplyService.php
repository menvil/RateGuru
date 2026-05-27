<?php

namespace App\Services\Comments;

use App\Actions\Comments\AddCommentAction;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CommentReplyService
{
    public function __construct(
        private readonly AddCommentAction $addCommentAction,
    ) {}

    public function canStartReply(int $postId, int $commentId): bool
    {
        return $this->replyTargetQuery($postId)
            ->whereKey($commentId)
            ->exists();
    }

    public function createReply(?User $user, int $postId, int $parentCommentId, string $body): Comment
    {
        if ($user === null) {
            throw CannotCommentException::becauseGuest();
        }

        $post = Post::query()->published()->find($postId);
        $parent = $this->replyTargetQuery($postId)->find($parentCommentId);

        if ($post === null || $parent === null) {
            throw CannotCommentException::becauseBodyIsInvalid('Reply target is unavailable.');
        }

        return $this->addCommentAction->handle(
            user: $user,
            post: $post,
            body: $body,
            parent: $parent,
        );
    }

    /**
     * @return Builder<Comment>
     */
    private function replyTargetQuery(int $postId): Builder
    {
        return Comment::query()
            ->where('post_id', $postId)
            ->where('status', CommentStatus::Visible)
            ->whereNull('parent_id');
    }
}
