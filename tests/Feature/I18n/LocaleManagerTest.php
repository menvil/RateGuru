<?php

use App\Support\Locale\LocaleManager;

it('checks whether locale is supported', function () {
    $manager = app(LocaleManager::class);

    foreach (supportedLocales() as $locale) {
        expect($manager->isSupported($locale))->toBeTrue();
    }

    expect($manager->isSupported(unsupportedLocale()))->toBeFalse();
});

it('returns fallback locale for unsupported locale', function () {
    expect(app(LocaleManager::class)->normalize(unsupportedLocale()))->toBe(config('locales.fallback'));
});

it('returns same locale when it is supported', function (string $locale) {
    expect(app(LocaleManager::class)->normalize($locale))->toBe($locale);
})->with(supportedLocales());

it('returns fallback locale', function () {
    expect(app(LocaleManager::class)->fallback())->toBe(config('locales.fallback'));
});

it('returns supported locales array', function () {
    $supported = app(LocaleManager::class)->supported();

    expect($supported)->toBe(config('locales.supported'));
});

it('returns the labels each locale is declared with', function () {
    $manager = app(LocaleManager::class);

    foreach (config('locales.supported') as $locale => $info) {
        expect($manager->label($locale))->toBe($info['label'])
            ->and($manager->nativeLabel($locale))->toBe($info['native']);
    }
});
