<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
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

it('has avatar_url column on users table', function () {
    expect(Schema::hasColumn('users', 'avatar_url'))->toBeTrue();
});

it('persists user avatar url', function () {
    $user = User::factory()->create(['avatar_url' => 'https://example.com/a.jpg']);

    expect($user->fresh()->avatar_url)->toBe('https://example.com/a.jpg');
});

it('allows nullable avatar url', function () {
    $user = User::factory()->create(['avatar_url' => null]);

    expect($user->fresh()->avatar_url)->toBeNull();
});

it('has role column on users table', function () {
    expect(Schema::hasColumn('users', 'role'))->toBeTrue();
});

it('defaults user role to user', function () {
    $user = User::query()->create([
        'name' => 'Chef Ivan',
        'email' => 'chef@example.com',
        'password' => 'password',
    ]);

    expect($user->fresh()->role)->toBe(UserRole::User);
});

it('casts user role to UserRole enum', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    expect($user->fresh()->role)->toBe(UserRole::Admin);
});

it('creates regular user role from factory', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::User);
});

it('has status column on users table', function () {
    expect(Schema::hasColumn('users', 'status'))->toBeTrue();
});

it('defaults user status to active', function () {
    $user = User::query()->create([
        'name' => 'Chef Ivan',
        'email' => 'status-chef@example.com',
        'password' => 'password',
    ]);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('casts user status to UserStatus enum', function () {
    $user = User::factory()->create(['status' => UserStatus::Banned]);

    expect($user->fresh()->status)->toBe(UserStatus::Banned);
});

it('creates active user status from factory', function () {
    $user = User::factory()->create();

    expect($user->status)->toBe(UserStatus::Active);
});
