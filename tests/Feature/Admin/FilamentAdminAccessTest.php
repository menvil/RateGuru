<?php

use App\Enums\UserStatus;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

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

it('never lets a persona into /admin, whatever its status', function (string $state) {
    $persona = User::factory()->persona()->create(['status' => UserStatus::from($state)]);

    $this->actingAs($persona)->get('/admin')->assertForbidden();
})->with(['active', 'limited', 'banned', 'shadowbanned']);

it('refuses a persona at the panel sign-in, even with a password written straight into its row', function () {
    $persona = User::factory()->persona()->create();
    User::query()->whereKey($persona->id)->update(['password' => Hash::make('password')]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(Login::class)
        ->set('data.email', $persona->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasErrors(['data.email' => __('filament-panels::auth/pages/login.messages.failed')]);

    $this->assertGuest();
});
