<?php

namespace App\Actions\Posts;

use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;

final class UnsavePostAction
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
        private readonly DomainLogger $logger,
    ) {}

    public function handle(User $user, Post $post): void
    {
        if (! $this->settings->current()->featureFlag('show_saved_posts')) {
            throw new SavedPostsDisabledException;
        }

        PostSave::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->delete();

        $this->logger->info('saved_posts.unsaved', ['user_id' => $user->id, 'post_id' => $post->id]);
    }
}
