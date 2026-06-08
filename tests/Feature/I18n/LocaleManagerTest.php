<?php

use App\Support\Locale\LocaleManager;

it('checks whether locale is supported', function () {
    $manager = app(LocaleManager::class);

    expect($manager->isSupported('en'))->toBeTrue();
    expect($manager->isSupported('ru'))->toBeTrue();
    expect($manager->isSupported('bg'))->toBeTrue();
    expect($manager->isSupported('de'))->toBeFalse();
});

it('returns fallback locale for unsupported locale', function () {
    expect(app(LocaleManager::class)->normalize('de'))->toBe('en');
});

it('returns same locale when it is supported', function () {
    expect(app(LocaleManager::class)->normalize('ru'))->toBe('ru');
});

it('returns fallback locale', function () {
    expect(app(LocaleManager::class)->fallback())->toBe('en');
});

it('returns supported locales array', function () {
    $supported = app(LocaleManager::class)->supported();

    expect($supported)->toHaveKeys(['en', 'ru', 'bg']);
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
