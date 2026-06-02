<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('denies admin access to normal user', function () {
    actingAs(User::factory()->create());

    visit('/admin')
        ->assertPathIs('/admin')
        ->assertSee('403');
});

it('allows moderator to access admin panel', function () {
    actingAs(User::factory()->moderator()->create());

    visit('/admin')
        ->assertPathIs('/admin')
        ->assertSee('Dashboard')
        ->assertDontSee('Moderation Dashboard')
        ->assertNotPresent('[data-testid="admin-dashboard"]');
});
