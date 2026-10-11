<?php

use App\Models\User;

it('defaults notify_followed_author_posts to true', function () {
    // Created without the factory, which sets the flag itself, so the default
    // under test is the one a newly registered account gets.
    $user = User::query()->create([
        'name' => 'Follower',
        'email' => 'follower@example.com',
        'password' => 'password',
    ]);

    expect($user->fresh()->notify_followed_author_posts)->toBeTrue();
});

it('can disable notify_followed_author_posts', function () {
    $user = User::factory()->create(['notify_followed_author_posts' => true]);

    $user->update(['notify_followed_author_posts' => false]);

    expect($user->fresh()->notify_followed_author_posts)->toBeFalse();
});
