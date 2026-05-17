<?php

namespace App\Actions\Posts;

use App\Data\Posts\CreatePostData;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;

final class CreatePostAction
{
    public function handle(User $user, CreatePostData $data): Post
    {
        return Post::create([
            'user_id'       => $user->id,
            'title'         => $data->title,
            'description'   => $data->description,
            'source_url'    => $data->sourceUrl,
            'origin_truth'  => $data->originTruth,
            'cuisine_truth' => $data->cuisineTruth,
            'status'        => PostStatus::Pending,
            'published_at'  => null,
        ]);
    }
}
