<?php

use App\Models\Post;
use App\Models\Tag;

it('belongs to many tags', function () {
    $post = Post::factory()->create();
    $tags = Tag::factory()->count(2)->create();

    $post->tags()->attach($tags);

    expect($post->tags()->count())->toBe(2);
    expect($post->tags()->pluck('id')->all())
        ->toEqualCanonicalizing($tags->pluck('id')->all());
});
