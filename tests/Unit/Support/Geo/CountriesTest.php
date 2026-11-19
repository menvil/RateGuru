<?php

use App\Support\Geo\Countries;

it('lists every ISO 3166-1 country and Kosovo, each by its upper-case code and English name', function () {
    $all = Countries::all();

    expect($all)->toHaveCount(250)
        ->and(array_keys($all))->each->toMatch('/^[A-Z]{2}$/')
        ->and(array_values($all))->each->not->toBe('')
        ->and(array_keys($all))->toBe(collect($all)->keys()->sort()->values()->all())
        ->and(Countries::has('XK'))->toBeTrue()
        ->and(Countries::name('GB'))->toBe('United Kingdom')
        ->and(Countries::name('HK'))->toBe('Hong Kong');
});

it('draws a country’s flag from its code’s letters, and a globe for Kosovo and for a code it does not know', function () {
    expect(Countries::flag('DE'))->toBe("\u{1F1E9}\u{1F1EA}")
        ->and(Countries::flag('US'))->toBe('🇺🇸')
        ->and(Countries::flag('AZ'))->toBe('🇦🇿')
        ->and(Countries::flag('XK'))->toBe('🌐')
        ->and(Countries::flag('ZZ'))->toBe(Countries::GLOBE)
        ->and(Countries::flag('de'))->toBe(Countries::GLOBE)
        ->and(collect(Countries::all())->keys()->reject(fn (string $code): bool => $code === 'XK')->map(Countries::flag(...))->unique())->toHaveCount(249);
});

it('knows no code outside the list and names it by itself', function () {
    expect(Countries::has('ZZ'))->toBeFalse()
        ->and(Countries::has('EU'))->toBeFalse()
        ->and(Countries::has('gb'))->toBeFalse()
        ->and(Countries::name('ZZ'))->toBe('ZZ');
});
