<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('can create a comment with factory', function () {
    $comment = Comment::factory()->create();

    expect($comment)->toBeInstanceOf(Comment::class);
    expect($comment->exists)->toBeTrue();
    expect($comment->post)->toBeInstanceOf(Post::class);
    expect($comment->user)->toBeInstanceOf(User::class);
});
