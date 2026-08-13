<?php

declare(strict_types=1);

namespace App\Actions\Fixtures;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;

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
}
