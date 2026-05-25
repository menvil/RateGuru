<?php

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds demo users', function () {
    $this->seed(DemoUsersSeeder::class);

    expect(User::query()->where('email', 'alice@rateguru.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'bob@rateguru.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'trusted@rateguru.test')->exists())->toBeTrue();
});

it('seeds users with expected roles and statuses', function () {
    $this->seed(DemoUsersSeeder::class);

    expect(User::query()->where('email', 'alice@rateguru.test')->firstOrFail()->status)
        ->toBe(UserStatus::Active);
    expect(User::query()->where('email', 'alice@rateguru.test')->firstOrFail()->role)
        ->toBe(UserRole::User);

    $trusted = User::query()->where('email', 'trusted@rateguru.test')->firstOrFail();

    expect($trusted->status)->toBe(UserStatus::Active);
    expect($trusted->trust_level)->toBeGreaterThanOrEqual(MarkUserTrustedAction::TRUSTED_LEVEL);

    expect(User::query()->where('email', 'banned@rateguru.test')->firstOrFail()->status)
        ->toBe(UserStatus::Banned);
    expect(User::query()->where('email', 'shadow@rateguru.test')->firstOrFail()->status)
        ->toBe(UserStatus::Shadowbanned);
});

it('seeds users with hashed passwords', function () {
    $this->seed(DemoUsersSeeder::class);

    $user = User::query()->where('email', 'alice@rateguru.test')->firstOrFail();

    expect(Hash::check('password', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('password');
});

it('does not seed demo users in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DemoUsersSeeder())->run();

    expect(User::query()->where('email', 'alice@rateguru.test')->exists())->toBeFalse();
});
