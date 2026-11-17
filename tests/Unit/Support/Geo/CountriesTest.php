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

it('knows no code outside the list and names it by itself', function () {
    expect(Countries::has('ZZ'))->toBeFalse()
        ->and(Countries::has('EU'))->toBeFalse()
        ->and(Countries::has('gb'))->toBeFalse()
        ->and(Countries::name('ZZ'))->toBe('ZZ');
});
