<?php

use App\Support\Locale\LanguageRules;
use Carbon\Carbon;

/**
 * The plural rule and the date words of every installed language: Laravel's
 * and Carbon's own tables, told by LanguageRules about the languages they do
 * not cover by code.
 */
function pluralForm(string $line, int $number, string $locale): string
{
    return app('translator')->getSelector()->choose($line, $number, $locale);
}

it('picks Montenegrin plural forms by Serbian rule, not always the first', function (int $number, string $form) {
    expect(pluralForm('one|few|many', $number, 'cnr'))->toBe($form)
        ->and(pluralForm('one|few|many', $number, 'sr'))->toBe($form);
})->with([
    [1, 'one'], [21, 'one'], [2, 'few'], [4, 'few'], [22, 'few'],
    [5, 'many'], [11, 'many'], [12, 'many'], [0, 'many'],
]);

it('knows a plural rule for every installed language, by its own code or a shared one', function (string $locale) {
    $rules = (string) file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Translation/MessageSelector.php'));

    // Without one Laravel gives every number the first form, silently.
    expect($rules)->toContain("case '".LanguageRules::pluralLocale($locale)."':");
})->with(supportedLocales());

it('writes Serbian dates in Cyrillic and Montenegrin ones in Montenegrin Latin', function () {
    $twoHoursAgo = fn (): string => Carbon::now()->subHours(2)->diffForHumans();

    app()->setLocale('sr');
    expect($twoHoursAgo())->toBe('пре 2 сата');

    app()->setLocale('cnr');
    expect($twoHoursAgo())->toBe('prije 2 sata');

    app()->setLocale('en');
    expect($twoHoursAgo())->toBe('2 hours ago');
});

it('has Carbon date words for every installed language', function (string $locale) {
    expect(Carbon::getAvailableLocales())->toContain(LanguageRules::dateLocale($locale));
})->with(supportedLocales());
