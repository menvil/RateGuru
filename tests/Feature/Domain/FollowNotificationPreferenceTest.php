<?php

use App\Models\User;

it('stores followed author post notification preference', function () {
    $user = User::factory()->create([
        'notify_followed_author_posts' => false,
    ]);

    expect($user->notify_followed_author_posts)->toBeFalse();
});

it('defaults notify_followed_author_posts to true', function () {
    $user = User::factory()->create();

    expect($user->notify_followed_author_posts)->toBeTrue();
});

it('can disable notify_followed_author_posts', function () {
    $user = User::factory()->create(['notify_followed_author_posts' => true]);

    $user->update(['notify_followed_author_posts' => false]);

    expect($user->fresh()->notify_followed_author_posts)->toBeFalse();
});
