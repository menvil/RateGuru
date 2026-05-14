<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

it('allows post to belong to many tags', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    $tag = Tag::create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $post->tags()->attach($tag);

    expect($post->tags()->first()->id)->toBe($tag->id);
});
