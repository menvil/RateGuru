<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has theme preference column on users table', function () {
    expect(Schema::hasColumn('users', 'theme_preference'))->toBeTrue();
});

it('stores user theme preference', function () {
    $user = User::factory()->create([
        'theme_preference' => 'dark',
    ]);

    expect($user->theme_preference)->toBe('dark');
});

it('allows null theme preference', function () {
    $user = User::factory()->create([
        'theme_preference' => null,
    ]);

    expect($user->theme_preference)->toBeNull();
});
