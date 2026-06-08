<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has locale column on users table', function () {
    expect(Schema::hasColumn('users', 'locale'))->toBeTrue();
});

it('can store user locale preference', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    expect($user->fresh()->locale)->toBe('ru');
});

it('allows null locale for users', function () {
    $user = User::factory()->create(['locale' => null]);

    expect($user->fresh()->locale)->toBeNull();
});
