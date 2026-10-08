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

    $button = 'document.querySelector(\'[data-testid="profile-header"] [data-testid="follow-button"]\')';
    $follows = fn (): bool => Follow::query()->where('follower_id', $viewer->id)->where('author_id', $author->id)->exists();

    $page = visit(route('profile.show', $author->username))
        ->assertPresent('[data-testid="profile-header"] [data-testid="follow-button"]');

    expect($follows())->toBeFalse();

    $page->click('[data-testid="profile-header"] [data-testid="follow-button"]');

    // The server has answered: the button it rendered back is pressed, says
    // so, and the follow is stored. The page itself is checked rather than for
    // "Following" anywhere on it, which "Followers" would also satisfy.
    waitForScript($page, "{$button}.getAttribute('aria-pressed')", 'true');
    expect($page->script("{$button}.innerText.trim()"))->toBe('Following')
        ->and($follows())->toBeTrue();

    $page->click('[data-testid="profile-header"] [data-testid="follow-button"]');

    waitForScript($page, "{$button}.getAttribute('aria-pressed')", 'false');
    expect($page->script("{$button}.innerText.trim()"))->toBe('Follow')
        ->and($follows())->toBeFalse();
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

    $page = visit(route('profile.show', $author->username))->resize(...MobileViewports::MOBILE);

    waitForViewportSize($page, ...MobileViewports::MOBILE);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    // 1px tolerance for subpixel rendering differences across browsers
    expect($overflow)->toBeLessThanOrEqual(1);
});
