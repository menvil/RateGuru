<?php

use App\Livewire\Notifications\NotificationBell;
use App\Models\User;
use Livewire\Livewire;

it('notifications dropdown keeps within the screen and wraps long messages', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->html();

    expect($html)
        ->toContain('data-testid="notifications-dropdown"')
        ->toContain('max-w-[calc(100vw-2rem)]')
        ->toContain('break-words');
});
