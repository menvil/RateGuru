<?php

use App\Models\User;

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
