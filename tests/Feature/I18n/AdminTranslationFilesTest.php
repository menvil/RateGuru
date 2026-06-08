<?php

it('has admin translation files for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        expect(file_exists(lang_path("{$locale}/admin.php")))->toBeTrue();
    }
});

it('keeps admin translation keys consistent across locales', function () {
    $en = array_keys(require lang_path('en/admin.php'));
    $ru = array_keys(require lang_path('ru/admin.php'));
    $bg = array_keys(require lang_path('bg/admin.php'));

    expect($ru)->toEqual($en);
    expect($bg)->toEqual($en);
});
