<?php

use App\Livewire\Notifications\NotificationBell;
use App\Models\User;
use Livewire\Livewire;

it('can render notification bell for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertStatus(200)
        ->assertSee('data-testid="notification-bell"', false);
});

it('does not render notification bell for guest', function () {
    Livewire::test(NotificationBell::class)
        ->assertDontSee('data-testid="notification-bell"', false);
});
