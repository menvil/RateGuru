<?php

namespace App\Actions\Follows;

use App\Models\Follow;
use App\Models\User;
use App\Support\Observability\DomainLogger;

final class UnfollowAuthorAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    public function handle(User $follower, User $author): void
    {
        Follow::query()
            ->where('follower_id', $follower->id)
            ->where('author_id', $author->id)
            ->delete();

        $this->logger->info('follows.unfollowed', ['user_id' => $follower->id, 'author_id' => $author->id]);
    }
}
