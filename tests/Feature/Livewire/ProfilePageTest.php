<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('can render profile page component', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertStatus(200);
});

it('fails profile page component for missing username', function () {
    expect(fn () => Livewire::test(ProfilePage::class, ['username' => 'missing_user']))
        ->toThrow(ModelNotFoundException::class);
});

it('renders selected user in profile page component', function () {
    User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('chef_ivan')
        ->assertSee('Ivan Chef');
});

it('renders profile header section', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-header"', false);
});

it('renders user avatar on profile page', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-avatar"', false)
        ->assertSee('https://example.test/avatar.jpg', false);
});

it('renders avatar fallback when user has no avatar url', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
        'avatar_url' => null,
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-avatar"', false);
});
