<?php

namespace App\Actions\Votes;

use App\Enums\OriginType;
use App\Models\Post;
use App\Models\User;

final class VoteOriginAction
{
    public function handle(?User $user, Post $post, OriginType $origin): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
