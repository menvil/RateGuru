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

        $post = Post::query()->find($postId);

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
