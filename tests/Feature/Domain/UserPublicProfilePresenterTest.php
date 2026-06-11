<?php

use App\Models\User;
use App\Support\Profile\UserPublicProfile;
use App\Support\Profile\UserPublicProfilePresenter;

it('builds public profile without private fields', function () {
    $user = User::factory()->create([
        'display_name' => 'Ivan',
        'bio' => 'Bio',
        'rating_activity_visibility' => 'private',
    ]);

    $profile = app(UserPublicProfilePresenter::class)->forUser($user);

    expect($profile->displayName)->toBe('Ivan');
    expect($profile->bio)->toBe('Bio');
    expect($profile)->not->toHaveKey('notify_followed_author_posts');
    expect($profile)->not->toHaveKey('rating_activity_visibility');
});

it('exposes public fields only', function () {
    $user = User::factory()->create([
        'username' => 'ivan_test',
        'display_name' => 'Ivan Test',
        'bio' => 'Some bio',
        'profile_website_url' => 'https://example.com',
    ]);

    $profile = app(UserPublicProfilePresenter::class)->forUser($user);

    expect($profile)->toBeInstanceOf(UserPublicProfile::class);
    expect($profile->id)->toBe($user->id);
    expect($profile->username)->toBe('ivan_test');
    expect($profile->displayName)->toBe('Ivan Test');
    expect($profile->bio)->toBe('Some bio');
    expect($profile->websiteUrl)->toBe('https://example.com');
    expect($profile->joinedAt)->not->toBeNull();
});

it('falls back to name when display_name is null', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
        'display_name' => null,
    ]);

    $profile = app(UserPublicProfilePresenter::class)->forUser($user);

    expect($profile->displayName)->toBe('John Doe');
});

it('falls back to username when both name and display_name are null', function () {
    $user = User::factory()->create([
        'name' => '',
        'display_name' => null,
        'username' => 'fallback_user',
    ]);

    $profile = app(UserPublicProfilePresenter::class)->forUser($user);

    expect($profile->displayName)->toBe('fallback_user');
});

it('resolves avatar url from avatar_path when set', function () {
    $user = User::factory()->create([
        'avatar_path' => 'avatars/test.jpg',
        'avatar_url' => null,
    ]);

    $profile = app(UserPublicProfilePresenter::class)->forUser($user);

    expect($profile->avatarUrl)->toContain('avatars/test.jpg');
});

it('returns avatar_url when avatar_path is null', function () {
    $user = User::factory()->create([
        'avatar_path' => null,
        'avatar_url' => 'https://cdn.example.com/avatar.jpg',
    ]);

    $profile = app(UserPublicProfilePresenter::class)->forUser($user);

    expect($profile->avatarUrl)->toBe('https://cdn.example.com/avatar.jpg');
});
