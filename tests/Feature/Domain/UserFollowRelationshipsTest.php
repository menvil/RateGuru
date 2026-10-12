<?php

use App\Models\Follow;
use App\Models\User;

it('exposes one follow row from both users through all four follow relationships', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    expect($follower->followingAuthors->first()->is($author))->toBeTrue();
    expect($author->followers->first()->is($follower))->toBeTrue();

    expect($follower->followingRelations)->toHaveCount(1);
    expect($follower->followingRelations->first()->author_id)->toBe($author->id);

    expect($author->followerRelations)->toHaveCount(1);
    expect($author->followerRelations->first()->follower_id)->toBe($follower->id);
});
