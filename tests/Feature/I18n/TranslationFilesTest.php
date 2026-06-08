<?php

it('has base translation files for supported locales', function () {
    foreach (['en', 'ru', 'bg'] as $locale) {
        expect(file_exists(lang_path("{$locale}/ui.php")))->toBeTrue();
        expect(file_exists(lang_path("{$locale}/auth.php")))->toBeTrue();
        expect(file_exists(lang_path("{$locale}/validation.php")))->toBeTrue();
    }
});

it('keeps ui translation keys consistent across locales', function () {
    $en = array_keys(require lang_path('en/ui.php'));
    $ru = array_keys(require lang_path('ru/ui.php'));
    $bg = array_keys(require lang_path('bg/ui.php'));

    expect($ru)->toEqual($en);
    expect($bg)->toEqual($en);
});
