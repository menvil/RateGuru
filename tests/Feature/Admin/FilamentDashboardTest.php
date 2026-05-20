<?php

use App\Models\User;

it('renders the filament dashboard placeholder for moderator', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru Admin')
        ->assertSee('Moderation and content tools will appear here');
});

it('renders the filament dashboard placeholder for admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru Admin');
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
