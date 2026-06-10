<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

final class SavePostAction
{
    public function __construct(private readonly ProjectSettingsManager $settings) {}

    public function handle(User $user, Post $post): void
    {
        if (! $this->settings->current()->featureFlag('show_saved_posts')) {
            throw new SavedPostsDisabledException;
        }

        if ($post->status !== PostStatus::Published) {
            throw CannotSavePostException::postNotViewable();
        }

        PostSave::query()->firstOrCreate([
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);
    }
}
