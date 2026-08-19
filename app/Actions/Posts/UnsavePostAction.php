<?php

namespace App\Actions\Posts;

use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;

final class UnsavePostAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly ProjectSettingsManager $settings,
        private readonly DomainLogger $logger,
    ) {}

    public function handle(User $user, Post $post): void
    {
        if (! $this->settings->current()->featureFlag('show_saved_posts')) {
            throw new SavedPostsDisabledException;
        }

        DB::transaction(function () use ($user, $post): void {
            // Lock order: Actor User -> Post -> PostSave. Saved posts are
            // private state: every living account (sanctions included) may
            // manage them, a Deleted tombstone may not — PR-B removed its
            // rows and a stale request must never recreate them.
            $lockedActor = $this->lockActor($user);

            if ($lockedActor === null || ! $lockedActor->canAuthenticate()) {
                throw CannotSavePostException::userNotAllowed();
            }

            // Save rows on a post that is no longer live (author-deleted or
            // moderation-hidden) are retained state — no save/unsave
            // mutation may touch them, even through a stale instance.
            $lockedPost = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null || ! $lockedPost->canBeSaved()) {
                throw CannotSavePostException::postNotViewable();
            }

            PostSave::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->delete();
        });

        $this->logger->info('saved_posts.unsaved', ['user_id' => $user->id, 'post_id' => $post->id]);
    }
}
