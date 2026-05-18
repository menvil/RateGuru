<?php

namespace App\Actions\Votes;

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\User;

final class VotePostAction
{
    public function handle(User $user, Post $post, VoteType $type): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
