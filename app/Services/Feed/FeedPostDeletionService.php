<?php

namespace App\Services\Feed;

use App\Actions\Posts\DeletePostAction;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Models\User;

final class FeedPostDeletionService
{
    public function __construct(
        private readonly DeletePostAction $deletePostAction,
    ) {}

    public function deleteForUser(?User $user, int $postId): FeedPostDeletionResult
    {
        if ($user === null) {
            return FeedPostDeletionResult::skipped();
        }

        // With the deleted ones: a stale card of a post deleted meanwhile is
        // the action's idempotent case, deleted as far as the list is concerned.
        $post = Post::withTrashed()->find($postId);

        if ($post === null) {
            return FeedPostDeletionResult::skipped();
        }

        try {
            $this->deletePostAction->handle($user, $post);
        } catch (CannotDeletePostException $e) {
            return FeedPostDeletionResult::failed($e->getMessage());
        }

        return FeedPostDeletionResult::deleted();
    }
}
