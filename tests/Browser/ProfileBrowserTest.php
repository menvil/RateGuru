<?php

use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('switches from another tab back to the posts tab in browser', function () {
    // A profile opens on its posts, so clicking Posts first would prove
    // nothing: the test leaves for Activity and comes back.
    $user = User::factory()->create([
        'username' => 'profile-browser-test',
        'rating_activity_visibility' => 'public',
    ]);

    $page = visit(route('profile.show', $user->username))
        ->assertPresent('[data-testid="profile-page"]')
        ->assertPresent('[data-testid="profile-posts-tab"]')
        ->click('[data-testid="profile-tab-activity"]')
        ->assertPresent('[data-testid="profile-activity-tab"]');

    expect($page->script('document.querySelector(\'[data-testid="profile-posts-tab"]\') === null'))->toBeTrue();

    $page->click('[data-testid="profile-tab-posts"]')
        ->assertPresent('[data-testid="profile-posts-tab"]');

    expect($page->script('document.querySelector(\'[data-testid="profile-activity-tab"]\') === null'))->toBeTrue();
})->group('browser');

it('opens a post from its card on a profile with the default view mode, which has no detail column there', function () {
    $author = User::factory()->create(['username' => 'night_owl']);
    Post::factory()->published()->for($author)->create(['title' => 'A post opened from a profile']);

    $page = visit(route('profile.show', $author->username))
        ->resize(1440, 900)
        ->click('[data-testid="post-card"]');

    waitForPostDetailOverlayOpen($page);

    $page->assertSeeIn('[data-testid="post-detail-overlay"] [data-testid="post-drawer-title"]', 'A post opened from a profile');
});

it('closes the report of an account on Escape', function () {
    $author = User::factory()->create(['username' => 'reported_author']);
    actingAs(User::factory()->create());

    $page = visit(route('profile.show', $author->username));
    $dialog = "[...document.querySelectorAll('[data-testid=report-user-button] [role=dialog]')].some((dialog) => dialog.checkVisibility())";

    $page->click('[data-testid="report-user-button"] > button');
    waitForScript($page, $dialog);

    $page->keys(':focus', 'Escape');

    waitForScript($page, $dialog, false);
});
