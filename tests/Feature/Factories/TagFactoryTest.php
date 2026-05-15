<?php

use App\Models\Tag;

it('can create a tag with factory', function () {
    $tag = Tag::factory()->create();

    expect($tag)->toBeInstanceOf(Tag::class);
    expect($tag->exists)->toBeTrue();
    expect($tag->slug)->not->toBeEmpty();
    expect($tag->name)->not->toBeEmpty();
});
