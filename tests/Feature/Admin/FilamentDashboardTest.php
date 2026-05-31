<?php

use App\Models\User;

it('renders the moderation dashboard for moderator', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Moderation Dashboard')
        ->assertSee('data-testid="admin-dashboard"', false);
});

it('renders the moderation dashboard for admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Moderation Dashboard');
});

it('renders RateGuru branding in the admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru');
});

it('does not render the filament dashboard placeholder for a normal user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    expect($response->getStatusCode())->toBe(403);
});
