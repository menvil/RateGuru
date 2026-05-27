<?php

use App\Models\User;

use function Pest\Laravel\assertAuthenticated;

it('allows user to log in from browser', function () {
    User::factory()->create([
        'email' => 'browser-login@rateguru.test',
    ]);

    visit(route('login'))
        ->assertPresent('[data-testid="login-form"]')
        ->type('[data-testid="login-email"]', 'browser-login@rateguru.test')
        ->type('[data-testid="login-password"]', 'password')
        ->click('[data-testid="login-submit"]')
        ->assertPathIs(route('feed', absolute: false));

    assertAuthenticated();
});
