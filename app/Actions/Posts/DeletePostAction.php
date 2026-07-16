<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeletePostAction
{
    public function handle(User $user, Post $post): void
    {
        if (! $user->can('deleteFromFeed', $post)) {
            throw CannotDeletePostException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($post): void {
            $post->forceFill(['status' => PostStatus::Deleted])->save();
            $post->delete();
        });
    }
}
