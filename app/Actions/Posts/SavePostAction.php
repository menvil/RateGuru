<?php

namespace App\Actions\Posts;

use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;

final class SavePostAction
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

        if (! $post->canBeSaved()) {
            throw CannotSavePostException::postNotViewable();
        }

        $postSave = DB::transaction(function () use ($user, $post): PostSave {
            // The pre-check ran on a possibly stale instance; re-read the
            // post under lock so a save can never land on a post that was
            // author-deleted or hidden in between.
            $lockedPost = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null || ! $lockedPost->canBeSaved()) {
                throw CannotSavePostException::postNotViewable();
            }

            return PostSave::query()->firstOrCreate([
                'user_id' => $user->id,
                'post_id' => $post->id,
            ]);
        });

        if ($postSave->wasRecentlyCreated) {
            $this->logger->info('saved_posts.saved', ['user_id' => $user->id, 'post_id' => $post->id]);
        }
    }
}
