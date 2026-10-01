<?php

use App\Models\User;

it('renders language switcher with supported locales', function () {
    $response = $this->get(route('feed'))->assertOk();

    foreach (config('locales.supported') as $info) {
        $response->assertSee($info['native']);
    }
});

it('renders language switcher for authenticated user', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('feed'))
        ->assertOk();

    foreach (config('locales.supported') as $info) {
        $response->assertSee($info['native']);
    }
});

it('shows each language with the flag it is declared with', function () {
    $response = $this->get(route('feed'))->assertOk();

    foreach (config('locales.supported') as $code => $info) {
        $response->assertSeeInOrder([$info['flag'], $info['native']])
            ->assertSee('data-testid="locale-option-'.$code.'"', false);
    }
});

it('lists only the languages the project offers', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $offered);

    $response = $this->get(route('feed'))->assertOk();

    foreach (array_diff(supportedLocales(), [$withheld]) as $code) {
        $response->assertSee('data-testid="locale-option-'.$code.'"', false)
            ->assertSee(config("locales.supported.{$code}.native"))
            ->assertSee(config("locales.supported.{$code}.flag"));
    }

    $response->assertDontSee('data-testid="locale-option-'.$withheld.'"', false)
        ->assertDontSee(config("locales.supported.{$withheld}.native"));
});
