<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\User;
use Livewire\Livewire;

it('renders profile page with mobile testids', function () {
    $user = User::factory()->create();

    Livewire::test(ProfilePage::class, ['username' => $user->username])
        ->assertSee('data-testid="profile-header"', false)
        ->assertSee('data-testid="profile-identity"', false);
});

it('profile identity container uses min-w-0 to prevent overflow', function () {
    $user = User::factory()->create();

    $html = Livewire::test(ProfilePage::class, ['username' => $user->username])->html();

    expect($html)->toContain('data-testid="profile-identity"');
    expect($html)->toContain('min-w-0');
});

it('profile display name truncates long names on mobile', function () {
    $user = User::factory()->create(['name' => str_repeat('VeryLongName', 10)]);

    $html = Livewire::test(ProfilePage::class, ['username' => $user->username])->html();

    expect($html)->toContain('truncate');
});
