<?php

namespace App\Actions\Posts;

use App\Data\Posts\PostSaveToggleResult;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TogglePostSaveAction
{
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
                PostSave::query()->create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                ]);
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
