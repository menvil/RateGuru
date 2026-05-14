<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('allows post to have many comments', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    $comment = Comment::create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Looks good',
    ]);

    expect($post->comments()->first()->id)->toBe($comment->id);
});
