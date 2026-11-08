<?php

declare(strict_types=1);

namespace App\Actions\Fixtures;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class PhysicalUserDeletionAction
{
    public function forbiddenInstanceDelete(User $user): void
    {
        $user->delete();
    }

    public function forbiddenForceDelete(User $user): void
    {
        $user->forceDelete();
    }

    public function forbiddenStaticDestroy(int $id): void
    {
        User::destroy($id);
    }

    public function forbiddenStaticForceDestroy(int $id): void
    {
        User::forceDestroy($id);
    }

    public function forbiddenQuietAndFailingDeletes(User $user): void
    {
        $user->deleteQuietly();
        $user->deleteOrFail();
    }

    // A user that may be null is deleted all the same when it is not.
    public function forbiddenDeleteOfTheSignedInUser(Request $request): void
    {
        $request->user()->delete();
    }

    public function forbiddenQueryDeletes(): void
    {
        User::query()->where('id', 1)->delete();
        User::where('id', 1)->forceDelete();
        User::truncate();
    }

    /** @param class-string<User> $users */
    public function forbiddenDestroyThroughAClassString(string $users): void
    {
        $users::destroy(1);
    }

    public function forbiddenRelationDelete(Post $post): void
    {
        $post->user()->delete();
    }

    // Deleting other models stays perfectly legal — the ban is specific to
    // the users table, whose rows must survive as tombstones.
    public function allowedPostDelete(Post $post): void
    {
        $post->delete();
    }

    public function allowedMediaAssetDelete(MediaAsset $asset): void
    {
        $asset->delete();
    }

    public function allowedPostQueryDelete(User $user): void
    {
        Post::query()->where('user_id', $user->id)->delete();
        $user->posts()->delete();
    }

    /** @param User|Builder<User> $target */
    public function forbiddenUnionDelete(User|Builder $target): void
    {
        $target->delete();
    }

    /** @param Post|Builder<Post> $target */
    public function allowedUnionDelete(Post|Builder $target): void
    {
        $target->delete();
    }
}
