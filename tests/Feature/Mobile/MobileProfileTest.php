<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\User;
use Livewire\Livewire;

it('profile identity stays inside the screen and long display names truncate on mobile', function () {
    $user = User::factory()->create(['name' => str_repeat('VeryLongName', 10)]);

    $html = Livewire::test(ProfilePage::class, ['username' => $user->username])->html();

    expect($html)
        ->toContain('data-testid="profile-identity"')
        ->toContain('min-w-0')
        ->toContain('truncate');
});
