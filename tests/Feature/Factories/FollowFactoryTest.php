<?php

use App\Models\Follow;
use App\Models\User;

it('creates follow model via factory', function () {
    $follow = Follow::factory()->create();

    expect($follow)->toBeInstanceOf(Follow::class);
    expect($follow->exists)->toBeTrue();
    expect($follow->follower)->toBeInstanceOf(User::class);
    expect($follow->author)->toBeInstanceOf(User::class);
});

it('factory does not create self-follow by default', function () {
    $follow = Follow::factory()->create();

    expect($follow->follower_id)->not->toBe($follow->author_id);
});

it('throws when factory would create a self-follow', function () {
    $user = User::factory()->create();

    expect(fn () => Follow::factory()->create([
        'follower_id' => $user->id,
        'author_id' => $user->id,
    ]))->toThrow(\InvalidArgumentException::class);
});

it('creates follow for specific users', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    $follow = Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    expect($follow->follower->id)->toBe($follower->id);
    expect($follow->author->id)->toBe($author->id);
});
