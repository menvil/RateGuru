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
    expect(app(LocaleManager::class)->normalize(unsupportedLocale()))->toBe('en');
});

it('returns same locale when it is supported', function (string $locale) {
    expect(app(LocaleManager::class)->normalize($locale))->toBe($locale);
})->with(supportedLocales());

it('returns fallback locale', function () {
    expect(app(LocaleManager::class)->fallback())->toBe('en');
});

it('returns supported locales array', function () {
    $supported = app(LocaleManager::class)->supported();

    expect($supported)->toBe(config('locales.supported'));
});

it('returns locale label', function () {
    expect(app(LocaleManager::class)->label('en'))->toBe('English');
    expect(app(LocaleManager::class)->label('ru'))->toBe('Russian');
    expect(app(LocaleManager::class)->label('bg'))->toBe('Bulgarian');
});

it('returns locale native label', function () {
    expect(app(LocaleManager::class)->nativeLabel('en'))->toBe('English');
    expect(app(LocaleManager::class)->nativeLabel('ru'))->toBe('Русский');
    expect(app(LocaleManager::class)->nativeLabel('bg'))->toBe('Български');
});
