<?php

use App\Models\User;
use Filament\Panel;

it('redirects guest from admin panel to filament login', function () {
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
});

it('registers the admin panel with id and path "admin"', function () {
    $panel = filament()->getPanel('admin');

    expect($panel)->not->toBeNull()
        ->and($panel->getId())->toBe('admin')
        ->and($panel->getPath())->toBe('admin');
});

it('denies access to non-admin panels even for an active admin', function () {
    $admin = User::factory()->admin()->create();
    $otherPanel = Panel::make()->id('other');

    expect($admin->canAccessPanel($otherPanel))->toBeFalse();
});

it('does not allow normal authenticated user to access /admin', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    expect($response->getStatusCode())->toBe(403);
});

it('does not allow banned moderator to access /admin', function () {
    $moderator = User::factory()->moderator()->banned()->create();

    $response = $this->actingAs($moderator)->get('/admin');

    expect($response->getStatusCode())->toBe(403);
});

it('allows moderator to access /admin', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk();
});

it('allows admin to access /admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk();
});
