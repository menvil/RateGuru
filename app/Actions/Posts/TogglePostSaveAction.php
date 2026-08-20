<?php

namespace App\Actions\Posts;

use App\Data\Posts\PostSaveToggleResult;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TogglePostSaveAction
{
    use LocksActorForWrite;

    public function handleForPostId(?User $user, int $postId): PostSaveToggleResult
    {
        if ($user === null) {
            return new PostSaveToggleResult(saved: false, message: 'Log in to save posts.');
        }

        $post = Post::query()->published()->find($postId);

        if ($post === null) {
            return new PostSaveToggleResult(saved: false, message: 'This post is unavailable.');
        }

        $saved = $this->handle($user, $post);

        return new PostSaveToggleResult(
            saved: $saved,
            message: $saved ? 'Saved' : 'Removed',
        );
    }

    public function isSavedByUser(?User $user, int $postId): bool
    {
        if ($user === null) {
            return false;
        }

        return PostSave::query()
            ->where('user_id', $user->id)
            ->where('post_id', $postId)
            ->exists();
    }

    public function handle(User $user, Post $post): bool
    {
        return DB::transaction(function () use ($user, $post): bool {
            // Lock order: Actor User -> Post -> PostSave. Saved posts are
            // private state: every living account (sanctions included) may
            // manage them, a Deleted tombstone may not — PR-B removed its
            // rows and a stale request must never recreate them.
            $lockedActor = $this->lockActor($user);

            if ($lockedActor === null || ! $lockedActor->canAuthenticate()) {
                throw CannotSavePostException::userNotAllowed();
            }

            // The caller's post instance may also be stale — a save/unsave
            // must never mutate state for a post that was author-deleted or
            // hidden in between.
            $lockedPost = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null || ! $lockedPost->canBeSaved()) {
                throw CannotSavePostException::postNotViewable();
            }

            $existing = PostSave::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->delete();

                return false;
            }

            try {
                DB::transaction(fn (): PostSave => PostSave::query()->create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                ]));
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                return PostSave::query()
                    ->where('user_id', $user->id)
                    ->where('post_id', $post->id)
                    ->exists();
            }

            return true;
        });
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique constraint')
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
