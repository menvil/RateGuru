<?php

it('has supported locales config', function () {
    expect(config('locales.fallback'))->toBe('en')
        ->and(config('locales.supported'))->toHaveKey('en');
});

it('describes every installed locale with a label, a native name and a flag', function () {
    foreach (config('locales.supported') as $locale => $info) {
        expect($locale)->toMatch('/^[a-z]{2}$/');

        foreach (['label', 'native', 'flag'] as $field) {
            expect($info[$field] ?? null)->toBeString("{$locale} has no {$field}")
                ->and(trim($info[$field]))->not->toBe('', "{$locale} has an empty {$field}");
        }
    }
});

it('keeps the technical fallback installed', function () {
    // It is the emergency catalog and the last resort when the project
    // settings resolve to nothing, so it must exist. A project need not
    // offer it — that is checked where projects choose their languages.
    expect(config('locales.supported'))->toHaveKey(config('locales.fallback'));
});
