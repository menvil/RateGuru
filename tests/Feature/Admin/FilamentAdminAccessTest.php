<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;

it('exposes filament admin panel at /admin path', function () {
    $response = $this->get('/admin');

    expect($response->getStatusCode())->not->toBe(404);
});

it('redirects guest from admin panel to login', function () {
    $this->get('/admin')->assertRedirect();
});

it('registers the admin panel with id and path "admin"', function () {
    $panel = filament()->getPanel('admin');

    expect($panel)->not->toBeNull()
        ->and($panel->getId())->toBe('admin')
        ->and($panel->getPath())->toBe('admin');
});

it('user implements FilamentUser contract', function () {
    expect(new User())->toBeInstanceOf(FilamentUser::class);
});

it('allows active admin to access the filament panel', function () {
    $admin = User::factory()->admin()->create();
    $panel = Filament::getPanel('admin');

    expect($admin->canAccessPanel($panel))->toBeTrue();
});

it('allows active moderator to access the filament panel', function () {
    $moderator = User::factory()->moderator()->create();
    $panel = Filament::getPanel('admin');

    expect($moderator->canAccessPanel($panel))->toBeTrue();
});

it('does not allow a normal active user to access the filament panel', function () {
    $user = User::factory()->create();
    $panel = Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('does not allow a banned moderator to access the filament panel', function () {
    $moderator = User::factory()->moderator()->banned()->create();
    $panel = Filament::getPanel('admin');

    expect($moderator->canAccessPanel($panel))->toBeFalse();
});

it('does not allow a banned admin to access the filament panel', function () {
    $admin = User::factory()->admin()->banned()->create();
    $panel = Filament::getPanel('admin');

    expect($admin->canAccessPanel($panel))->toBeFalse();
});
