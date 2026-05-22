<?php

use App\Models\User;

it('has profile route by username', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    $this->get(route('profile.show', ['username' => 'chef_ivan']))
        ->assertOk();
});

it('returns 404 for missing profile username', function () {
    $this->get(route('profile.show', ['username' => 'missing_user']))
        ->assertNotFound();
});
