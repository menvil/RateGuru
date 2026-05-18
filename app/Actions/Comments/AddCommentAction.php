<?php

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

final class AddCommentAction
{
    public function handle(?User $user, Post $post, string $body): Comment
    {
        return Comment::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'body' => trim($body),
            'status' => CommentStatus::Visible,
        ]);
    }
}
