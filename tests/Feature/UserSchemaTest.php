<?php

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

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

it('does not have the legacy avatar columns replaced by media_assets', function () {
    expect(Schema::hasColumn('users', 'avatar_path'))->toBeFalse();
    expect(Schema::hasColumn('users', 'avatar_url'))->toBeFalse();
});

it('persists user avatar asset id', function () {
    $user = User::factory()->withAvatar()->create();

    expect($user->fresh()->avatar_asset_id)->toBe($user->avatar_asset_id);
});

it('allows nullable avatar asset id', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    expect($user->fresh()->avatar_asset_id)->toBeNull();
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

it('defaults user trust level to trusted', function () {
    $user = User::query()->create([
        'name' => 'Chef Ivan',
        'email' => 'trust-chef@example.com',
        'password' => 'password',
    ]);

    expect($user->fresh()->trust_level)->toBe(MarkUserTrustedAction::TRUSTED_LEVEL);
});

it('stores user trust level', function () {
    $user = User::factory()->create(['trust_level' => 10]);

    expect($user->fresh()->trust_level)->toBe(10);
});
