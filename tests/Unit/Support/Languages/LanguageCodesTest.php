<?php

use App\Support\Languages\LanguageCodes;

/*
 * The site's language codes and the ones detectors use for some of them.
 */

it('turns a detector\'s code into the site\'s', function (string $code, ?string $site) {
    expect(LanguageCodes::toSite($code, supportedLocales()))->toBe($site);
})->with([
    'the same code' => ['ru', 'ru'],
    'Norwegian' => ['no', 'nb'],
    'Nynorsk' => ['nn', 'nb'],
    'Tagalog' => ['tl', 'fil'],
    'old Hebrew' => ['iw', 'he'],
    'old Indonesian' => ['in', 'id'],
    'a region' => ['pt-BR', 'pt'],
    'a script' => ['zh_Hans', 'zh'],
    'Serbo-Croatian' => ['sh', 'sr'],
    'upper case' => ['EN', 'en'],
    'a language the site does not install' => ['ko', null],
    'no language' => ['und', null],
]);

it('gives a detector the codes it knows the site\'s languages by', function () {
    $codes = LanguageCodes::forDetector(supportedLocales());

    expect($codes)->toHaveCount(count(supportedLocales()))
        ->toContain('no')
        ->toContain('tl')
        ->not->toContain('nb')
        ->not->toContain('fil')
        ->and(array_values(array_filter($codes, fn (string $code): bool => ! in_array($code, ['no', 'tl'], true))))
        ->toBe(array_values(array_filter(supportedLocales(), fn (string $code): bool => $code !== 'nb' && $code !== 'fil')));
});
