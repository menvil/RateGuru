<?php

use App\Models\User;

it('sets locale from authenticated user preference', function (string $locale) {
    $user = User::factory()->create(['locale' => $locale]);

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe($locale);
})->with(supportedLocales());

it('sets locale from session for guest users', function (string $locale) {
    $this->withSession(['locale' => $locale])
        ->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe($locale);
})->with(supportedLocales());

it('falls back to english for unsupported locale in session', function () {
    $this->withSession(['locale' => unsupportedLocale()])
        ->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('uses english when no locale preference set', function () {
    $this->get(route('feed'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});
