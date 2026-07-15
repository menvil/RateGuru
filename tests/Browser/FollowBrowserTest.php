<?php

use App\Models\Follow;
use App\Models\ProjectSettings;
use App\Models\User;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\actingAs;

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

    actingAs($viewer);

    visit(route('profile.show', $author->username))
        ->assertPresent('[data-testid="profile-header"] [data-testid="follow-button"]')
        ->click('[data-testid="profile-header"] [data-testid="follow-button"]')
        ->wait(0.5)
        ->assertSee('Following')
        ->click('[data-testid="profile-header"] [data-testid="follow-button"]')
        ->wait(0.5)
        ->assertSee('Follow');
});

it('does not show follow button on own profile in browser', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->create([
        'email' => 'follow-self-browser@example.com',
        'password' => bcrypt('password'),
        'username' => 'follow-self-user',
    ]);

    actingAs($user);

    visit(route('profile.show', $user->username))
        ->assertNotPresent('[data-testid="follow-button"]');
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

    actingAs($viewer);

    $overflow = visit(route('profile.show', $author->username))
        ->resize(...MobileViewports::MOBILE)
        ->wait(0.5)
        ->script('document.documentElement.scrollWidth - window.innerWidth');

    // 1px tolerance for subpixel rendering differences across browsers
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
        ->assertPresent('[data-testid="followers-count"]');
});
