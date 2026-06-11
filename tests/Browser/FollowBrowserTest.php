<?php

use App\Models\Follow;
use App\Models\ProjectSettings;
use App\Models\User;
use Tests\Browser\Support\MobileViewports;

it('can follow and unfollow author in browser', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create([
        'email' => 'follow-viewer@example.com',
        'password' => bcrypt('password'),
        'username' => 'follow-viewer',
    ]);

    $author = User::factory()->create([
        'username' => 'follow-author',
    ]);

    loginAs($viewer);

    visit(route('profile.show', $author->username))
        ->assertSee('data-testid="follow-button"', false)
        ->click('[data-testid="follow-button"]')
        ->pause(500)
        ->assertSee('Following')
        ->click('[data-testid="follow-button"]')
        ->pause(500)
        ->assertSee('Follow');
});

it('does not show follow button on own profile in browser', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->create([
        'email' => 'follow-self-browser@example.com',
        'password' => bcrypt('password'),
        'username' => 'follow-self-user',
    ]);

    loginAs($user);

    visit(route('profile.show', $user->username))
        ->assertDontSee('data-testid="follow-button"', false);
});

it('follow button does not overflow at mobile viewport', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create([
        'email' => 'follow-mobile@example.com',
        'password' => bcrypt('password'),
        'username' => 'follow-mobile-viewer',
    ]);

    $author = User::factory()->create([
        'username' => 'follow-mobile-author',
    ]);

    loginAs($viewer);

    $overflow = visit(route('profile.show', $author->username))
        ->resize(...MobileViewports::MOBILE)
        ->pause(500)
        ->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(1);
});

it('shows followers count on profile in browser', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $author = User::factory()->create([
        'username' => 'follow-count-browser-author',
    ]);

    Follow::factory()->count(2)->create([
        'author_id' => $author->id,
    ]);

    visit(route('profile.show', $author->username))
        ->assertSee('data-testid="followers-count"', false);
});
