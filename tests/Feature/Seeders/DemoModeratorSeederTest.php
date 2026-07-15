<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DemoModeratorSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds demo moderator account', function () {
    $this->seed(DemoModeratorSeeder::class);

    $moderator = User::query()->where('email', 'moderator@rateguru.test')->first();

    expect($moderator)->not->toBeNull();
    expect($moderator->username)->toBe('moderator');
    expect($moderator->role)->toBe(UserRole::Moderator);
    expect($moderator->status)->toBe(UserStatus::Active);
});

it('seeds demo moderator with hashed password', function () {
    $this->seed(DemoModeratorSeeder::class);

    $moderator = User::query()->where('email', 'moderator@rateguru.test')->firstOrFail();
    $expectedPassword = env('DEMO_MODERATOR_PASSWORD', 'password');

    expect(Hash::check($expectedPassword, $moderator->password))->toBeTrue();
    expect($moderator->password)->not->toBe($expectedPassword);
});

it('does not seed demo moderator in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DemoModeratorSeeder)->run();

    expect(User::query()->where('email', 'moderator@rateguru.test')->exists())->toBeFalse();
});

it('demo moderator can access filament panel', function () {
    $this->seed(DemoModeratorSeeder::class);

    $moderator = User::query()->where('email', 'moderator@rateguru.test')->firstOrFail();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk();
});
