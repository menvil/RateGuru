<?php

use App\Models\User;

it('renders language switcher with supported locales', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('English')
        ->assertSee('Русский')
        ->assertSee('Български');
});

it('renders language switcher for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('English')
        ->assertSee('Русский')
        ->assertSee('Български');
});
