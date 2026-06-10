<?php

use App\Models\Follow;
use App\Models\User;

it('user has following authors relationship', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    expect($follower->followingAuthors->first()->is($author))->toBeTrue();
});

it('user has followers relationship', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    expect($author->followers->first()->is($follower))->toBeTrue();
});

it('user has following relations pivot records', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    expect($follower->followingRelations)->toHaveCount(1);
    expect($follower->followingRelations->first()->author_id)->toBe($author->id);
});

it('user has follower relations pivot records', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    expect($author->followerRelations)->toHaveCount(1);
    expect($author->followerRelations->first()->follower_id)->toBe($follower->id);
});
