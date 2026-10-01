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

it('says of every installed locale whether a new project offers it', function () {
    // Bootstrap policy, not project state: a language a release adds ships
    // with false, so installing it never offers it to an existing project.
    foreach (config('locales.supported') as $locale => $info) {
        expect($info)->toHaveKey('enabled_by_default')
            ->and($info['enabled_by_default'])->toBeBool("{$locale} enabled_by_default must be true or false");
    }
});

it('keeps today\'s languages offered to projects that never chose', function () {
    // Every installed language offered before enabled_by_default existed has
    // to stay offered, or an existing project would lose one on deploy.
    foreach (config('locales.supported') as $locale => $info) {
        expect($info['enabled_by_default'])->toBeTrue("{$locale} was offered by default before the flag existed");
    }
});

it('offers the technical fallback by default, so a new project starts with a valid default', function () {
    // A new installation stores the fallback as its default and has chosen no
    // languages, so the fallback has to be among the ones offered by default.
    expect(config('locales.supported.'.config('locales.fallback').'.enabled_by_default'))->toBeTrue();
});

it('keeps the technical fallback installed', function () {
    // It is the emergency catalog and the last resort when the project
    // settings resolve to nothing, so it must exist. A project need not
    // offer it — that is checked where projects choose their languages.
    expect(config('locales.supported'))->toHaveKey(config('locales.fallback'));
});
