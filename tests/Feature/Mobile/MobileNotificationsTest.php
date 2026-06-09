<?php

use App\Livewire\Notifications\NotificationBell;
use App\Models\User;
use Livewire\Livewire;

it('renders notification bell component with testid', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('data-testid="notification-bell"', false)
        ->assertSee('data-testid="notifications-dropdown"', false);
});

it('notifications dropdown has mobile-safe max-width constraint', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->html();

    expect($html)->toContain('data-testid="notifications-dropdown"');
    expect($html)->toContain('max-w-[calc(100vw-2rem)]');
});

it('notification items use break-words for long messages', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->html();

    expect($html)->toContain('break-words');
});
