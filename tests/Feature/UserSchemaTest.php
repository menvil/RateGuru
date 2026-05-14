<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('has username column on users table', function () {
    expect(Schema::hasColumn('users', 'username'))->toBeTrue();
});

it('persists user username', function () {
    $user = User::factory()->create(['username' => 'chef_ivan']);

    expect($user->fresh()->username)->toBe('chef_ivan');
});

it('allows nullable username', function () {
    $user = User::factory()->create(['username' => null]);

    expect($user->fresh()->username)->toBeNull();
});

it('enforces unique username', function () {
    User::factory()->create(['username' => 'chef_ivan']);

    expect(fn () => User::factory()->create(['username' => 'chef_ivan']))
        ->toThrow(QueryException::class);
});

it('creates username from factory', function () {
    $user = User::factory()->create();

    expect($user->username)->toBeString()->not->toBe('');
});
