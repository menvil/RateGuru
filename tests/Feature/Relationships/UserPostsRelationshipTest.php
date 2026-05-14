<?php

use App\Models\Post;
use App\Models\User;

it('allows user to have many posts', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    expect($user->posts()->first()->id)->toBe($post->id);
});
