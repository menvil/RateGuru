<?php

namespace App\Actions\Follows;

use App\Models\Follow;
use App\Models\User;

final class UnfollowAuthorAction
{
    public function handle(User $follower, User $author): void
    {
        Follow::query()
            ->where('follower_id', $follower->id)
            ->where('author_id', $author->id)
            ->delete();
    }
}
