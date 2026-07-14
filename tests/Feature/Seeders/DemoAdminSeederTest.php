<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DemoAdminSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds demo admin account', function () {
    $this->seed(DemoAdminSeeder::class);

    $admin = User::query()->where('email', 'admin@rateguru.test')->first();

    expect($admin)->not->toBeNull();
    expect($admin->username)->toBe('admin');
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->status)->toBe(UserStatus::Active);
});

it('seeds demo admin with hashed password', function () {
    $this->seed(DemoAdminSeeder::class);

    $admin = User::query()->where('email', 'admin@rateguru.test')->firstOrFail();

    expect(Hash::check('password', $admin->password))->toBeTrue();
    expect($admin->password)->not->toBe('password');
});

it('does not seed demo admin in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DemoAdminSeeder)->run();

    expect(User::query()->where('email', 'admin@rateguru.test')->exists())->toBeFalse();
});
