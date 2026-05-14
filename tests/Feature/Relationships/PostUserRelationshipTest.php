<?php

use App\Models\Post;
use App\Models\User;

it('allows post to belong to user', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    expect($post->user->id)->toBe($user->id);
});
