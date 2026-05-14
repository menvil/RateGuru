<?php

use App\Models\User;

it('does not allow banned users to create content', function () {
    $user = User::factory()->banned()->create();

    expect($user->canCreateContent())->toBeFalse();
});

it('allows active users to create content', function () {
    $user = User::factory()->create();

    expect($user->canCreateContent())->toBeTrue();
});
