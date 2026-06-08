<?php

use App\Models\User;

it('sets locale from authenticated user preference', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe('ru');
});

it('sets locale from session for guest users', function () {
    $this->withSession(['locale' => 'bg'])
        ->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe('bg');
});

it('falls back to english for unsupported locale in session', function () {
    $this->withSession(['locale' => 'de'])
        ->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('uses english when no locale preference set', function () {
    $this->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});
