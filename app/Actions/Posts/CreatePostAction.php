<?php

namespace App\Actions\Posts;

use App\Data\Posts\CreatePostData;
use App\Models\Post;
use App\Models\User;

final class CreatePostAction
{
    public function handle(User $user, CreatePostData $data): Post
    {
        throw new \LogicException('Not implemented yet.');
    }
}
