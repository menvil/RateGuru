<?php

use App\Models\User;

it('feed page has screenshot target attribute', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-screenshot="feed-page"', false);
});

it('login page has screenshot target attribute', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-screenshot="auth-page"', false);
});

it('profile page has screenshot target attribute on profile header', function () {
    $user = User::factory()->create();

    $this->get(route('profile.show', ['username' => $user->username]))
        ->assertOk()
        ->assertSee('data-screenshot="profile-header"', false);
});
