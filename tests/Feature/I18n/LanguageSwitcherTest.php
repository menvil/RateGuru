<?php

use App\Models\User;

it('renders language switcher with supported locales', function () {
    offerEveryInstalledLocale();
    $response = $this->get(route('feed'))->assertOk();

    foreach (config('locales.supported') as $info) {
        $response->assertSee($info['native']);
    }
});

it('renders language switcher for authenticated user', function () {
    offerEveryInstalledLocale();
    $response = $this->actingAs(User::factory()->create())
        ->get(route('feed'))
        ->assertOk();

    foreach (config('locales.supported') as $info) {
        $response->assertSee($info['native']);
    }
});

it('shows each language with the flag it is declared with', function () {
    offerEveryInstalledLocale();
    $response = $this->get(route('feed'))->assertOk();

    foreach (config('locales.supported') as $code => $info) {
        $response->assertSeeInOrder([$info['flag'], $info['native']])
            ->assertSee('data-testid="locale-option-'.$code.'"', false);
    }
});

it('lists only the languages the project offers', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])));

    $response = $this->get(route('feed'))->assertOk();

    foreach (array_diff(supportedLocales(), [$withheld]) as $code) {
        $response->assertSee('data-testid="locale-option-'.$code.'"', false)
            ->assertSee(config("locales.supported.{$code}.native"))
            ->assertSee(config("locales.supported.{$code}.flag"));
    }

    $response->assertDontSee('data-testid="locale-option-'.$withheld.'"', false)
        ->assertDontSee(config("locales.supported.{$withheld}.native"));
});

it('shows the flags a little larger than the text beside them, in the trigger and in the menu', function () {
    offerEveryInstalledLocale();
    $flag = fn (string $emoji): string => '<span aria-hidden="true" class="text-lg leading-none" data-testid="locale-flag">'.$emoji.'</span>';

    $html = $this->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk()->getContent();

    preg_match_all('/data-testid="locale-switcher-trigger".*?<\/button>/s', $html, $triggers);
    preg_match_all('/data-testid="locale-option-[a-z]+".*?<\/button>/s', $html, $options);

    expect($triggers[0])->not->toBeEmpty()
        ->and($options[0])->not->toBeEmpty();

    foreach ($triggers[0] as $trigger) {
        expect($trigger)->toContain($flag(config('locales.supported.en.flag')));
    }

    foreach (config('locales.supported') as $code => $info) {
        expect(collect($options[0])->contains(fn (string $option): bool => str_contains($option, "locale-option-{$code}\"") && str_contains($option, $flag($info['flag']))))
            ->toBeTrue("the {$code} option has no larger flag");
    }
});
