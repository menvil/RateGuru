<?php

use Illuminate\Support\Arr;

it('keeps translation keys consistent across supported locales', function () {
    $files = ['ui', 'admin', 'auth', 'validation'];

    foreach ($files as $file) {
        $enPath = lang_path("en/{$file}.php");
        expect(file_exists($enPath))->toBeTrue("en/{$file}.php must exist");

        $en = Arr::dot(require $enPath);

        foreach (['ru', 'bg'] as $locale) {
            $localePath = lang_path("{$locale}/{$file}.php");
            expect(file_exists($localePath))->toBeTrue("{$locale}/{$file}.php must exist");

            $translated = Arr::dot(require $localePath);

            expect(array_keys($translated))->toEqual(
                array_keys($en),
                "Translation keys in {$locale}/{$file}.php must match en/{$file}.php"
            );
        }
    }
});
