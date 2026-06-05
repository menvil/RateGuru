<?php

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Models\User;

it('generates a canonical username', function () {
    $username = app(GenerateUniqueUsernameAction::class)->handle('  Test User-123!  ');

    expect($username)->toBe('test_user_123');
});

it('uses a fallback for names without canonical characters', function () {
    $username = app(GenerateUniqueUsernameAction::class)->handle('Иван');

    expect($username)->toBe('user');
});

it('adds a suffix while respecting the username length limit', function () {
    User::factory()->create(['username' => str_repeat('a', 32)]);

    $username = app(GenerateUniqueUsernameAction::class)->handle(str_repeat('A', 40));

    expect($username)->toBe(str_repeat('a', 30).'_2')
        ->and(strlen($username))->toBe(32);
});

it('stops after the maximum number of occupied candidates', function () {
    User::factory()->create(['username' => 'user']);

    for ($attempt = 2; $attempt <= GenerateUniqueUsernameAction::MAX_ATTEMPTS; $attempt++) {
        User::factory()->create(['username' => 'user_'.$attempt]);
    }

    expect(fn () => app(GenerateUniqueUsernameAction::class)->handle('User'))
        ->toThrow(RuntimeException::class, 'Unable to generate a unique username.');
});
