<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;

final class TogglePostSaveAction
{
    public function handle(User $user, Post $post): bool
    {
        $existing = PostSave::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        PostSave::query()->create([
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);

        return true;
    }
}
