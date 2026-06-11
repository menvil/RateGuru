<?php

use App\Models\User;

it('can open profile tabs in browser', function () {
    $user = User::factory()->create(['username' => 'profile-browser-test']);

    visit(route('profile.show', $user->username))
        ->assertPresent('[data-testid="profile-page"]')
        ->assertPresent('[data-testid="profile-tab-posts"]')
        ->click('[data-testid="profile-tab-posts"]')
        ->assertPresent('[data-testid="profile-posts-tab"]');
})->group('browser');

it('can navigate to activity tab in browser', function () {
    $user = User::factory()->create(['username' => 'profile-browser-activity']);

    visit(route('profile.show', $user->username))
        ->assertPresent('[data-testid="profile-tab-activity"]')
        ->click('[data-testid="profile-tab-activity"]')
        ->assertPresent('[data-testid="profile-activity-tab"]');
})->group('browser');
