<?php

use App\Models\User;

it('returns 404 for missing profile username', function () {
    $this->get(route('profile.show', ['username' => 'missing_user']))
        ->assertNotFound();
});

it('renders profile page for user', function () {
    User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
    ]);

    $this->get(route('profile.show', ['username' => 'chef_ivan']))
        ->assertOk()
        ->assertSee('chef_ivan')
        ->assertSee('Ivan Chef');
});
