<?php

it('has supported locales config', function () {
    expect(config('locales.fallback'))->toBe('en')
        ->and(config('locales.supported'))->toHaveKey('en');
});

it('describes every supported locale with an English and a native label', function () {
    foreach (config('locales.supported') as $locale => $info) {
        expect($locale)->toMatch('/^[a-z]{2}$/')
            ->and($info['label'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($info['native'] ?? null)->toBeString()->not->toBeEmpty();
    }
});
