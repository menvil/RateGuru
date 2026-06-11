<?php

use Illuminate\Support\Facades\Schema;

it('has profile fields on users table', function () {
    expect(Schema::hasColumns('users', [
        'display_name',
        'bio',
        'avatar_path',
        'profile_website_url',
        'rating_activity_visibility',
    ]))->toBeTrue();
});

it('defaults rating_activity_visibility to private', function () {
    $user = \App\Models\User::factory()->create();

    expect($user->rating_activity_visibility)->toBe('private');
});
