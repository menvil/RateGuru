<?php

use App\Models\User;

it('changes locale for guest by storing it in session', function (string $locale) {
    $this->post(route('locale.change'), ['locale' => $locale])
        ->assertRedirect();

    expect(session('locale'))->toBe($locale);
})->with(supportedLocales());

it('changes locale preference for authenticated user', function (string $locale) {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->post(route('locale.change'), ['locale' => $locale])
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe($locale);
})->with(supportedLocales());

it('also stores locale in session for authenticated user', function (string $locale) {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->post(route('locale.change'), ['locale' => $locale])
        ->assertRedirect();

    expect(session('locale'))->toBe($locale);
})->with(supportedLocales());

it('rejects unsupported locale change', function () {
    $this->post(route('locale.change'), ['locale' => unsupportedLocale()])
        ->assertSessionHasErrors('locale');
});
