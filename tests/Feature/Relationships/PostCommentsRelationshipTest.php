<?php

use App\Models\Comment;
use App\Models\Post;

it('has many comments', function () {
    $post = Post::factory()->create();

    $comments = Comment::factory()
        ->count(2)
        ->for($post)
        ->create();

    expect($post->comments()->count())->toBe(2);
    expect($post->comments()->pluck('id')->all())
        ->toEqualCanonicalizing($comments->pluck('id')->all());
});
