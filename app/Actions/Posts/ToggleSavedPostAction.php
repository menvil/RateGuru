<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\SavedPosts\ToggleSavedPostResult;

final class ToggleSavedPostAction
{
    public function __construct(
        private readonly SavePostAction $saveAction,
        private readonly UnsavePostAction $unsaveAction,
    ) {}

    public function handle(User $user, Post $post): ToggleSavedPostResult
    {
        $isSaved = PostSave::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->exists();

        if ($isSaved) {
            $this->unsaveAction->handle($user, $post);

            return new ToggleSavedPostResult(isSaved: false);
        }

        $this->saveAction->handle($user, $post);

        return new ToggleSavedPostResult(isSaved: true);
    }
}
