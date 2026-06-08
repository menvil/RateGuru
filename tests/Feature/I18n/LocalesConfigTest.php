<?php

it('has supported locales config', function () {
    expect(config('locales.fallback'))->toBe('en');
    expect(config('locales.supported'))->toHaveKeys(['en', 'ru', 'bg']);
});
it('does not allow unsupported locale keys in locale config', function () {
    foreach (array_keys(config('locales.supported')) as $locale) {
        expect($locale)->toMatch('/^[a-z]{2}$/');
    }
});
