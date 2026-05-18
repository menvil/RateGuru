<?php

namespace App\Actions\Votes;

use App\Enums\CuisineType;
use App\Models\Post;
use App\Models\User;

final class VoteCuisineAction
{
    public function handle(?User $user, Post $post, CuisineType $cuisine): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
