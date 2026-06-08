<?php

use Illuminate\Support\Arr;

it('has admin translation files for supported locales', function () {
    foreach (array_keys(config('locales.supported', [])) as $locale) {
        expect(file_exists(lang_path("{$locale}/admin.php")))->toBeTrue();
    }
});

it('keeps admin translation keys consistent across locales', function () {
    $en = Arr::dot(require lang_path('en/admin.php'));

    foreach (array_keys(config('locales.supported', [])) as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $translated = Arr::dot(require lang_path("{$locale}/admin.php"));

        expect(array_keys($translated))->toEqual(array_keys($en));
    }
});
