<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('allows comment to belong to user', function () {
    $postAuthor = User::factory()->create();
    $commentAuthor = User::factory()->create();

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'title' => 'Test dish',
    ]);

    $comment = Comment::create([
        'post_id' => $post->id,
        'user_id' => $commentAuthor->id,
        'body' => 'Looks good',
    ]);

    expect($comment->user->id)->toBe($commentAuthor->id);
});
