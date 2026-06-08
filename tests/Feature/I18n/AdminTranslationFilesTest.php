<?php

use Illuminate\Support\Arr;

it('has admin translation files for supported locales', function () {
    foreach (array_keys(config('locales.supported', [])) as $locale) {
        expect(file_exists(lang_path("{$locale}/admin.php")))->toBeTrue();
    }
});

it('keeps admin translation keys consistent across locales', function () {
    $enKeys = array_keys(Arr::dot(require lang_path('en/admin.php')));
    sort($enKeys);

    foreach (array_keys(config('locales.supported', [])) as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $localeKeys = array_keys(Arr::dot(require lang_path("{$locale}/admin.php")));
        sort($localeKeys);

        expect($localeKeys)->toEqual($enKeys);
    }
});
