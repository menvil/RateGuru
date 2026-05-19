<?php

namespace App\Actions\Moderation;

use App\Models\Post;
use App\Models\User;

final class RejectPostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
