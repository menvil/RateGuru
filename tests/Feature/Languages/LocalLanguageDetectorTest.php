<?php

use App\Services\Languages\LocalLanguageDetector;

/*
 * The language of a text, told at once on this server, held to the languages the site installs — close ones
 * kept apart.
 */

it('tells the language of a text, close languages apart', function (string $text, string $language) {
    expect(app(LocalLanguageDetector::class)->detect($text)?->language)->toBe($language);
})->with([
    'Russian' => ['Очень красивая фотография, мне нравится', 'ru'],
    'Bulgarian' => ['Много хубава снимка, браво на теб', 'bg'],
    'Ukrainian' => ['Дуже гарне фото, дякую тобі', 'uk'],
    'German' => ['Wunderschönes Foto, gefällt mir sehr', 'de'],
    'Norwegian, named the site\'s way' => ['Veldig fint bilde, takk skal du ha', 'nb'],
    'Czech' => ['Moc pěkná fotka, děkuji', 'cs'],
    'Slovak' => ['Veľmi pekná fotka, ďakujem ti', 'sk'],
]);

it('tells no language for a text without words that tell one', function (string $text) {
    expect(app(LocalLanguageDetector::class)->detect($text))->toBeNull();
})->with([
    'emoji' => ['😍🔥🔥'],
    'words every language shares' => ['wow ok lol'],
    'a link' => ['https://example.com/photo'],
    'nothing' => [''],
]);

it('counts a text of a few letters for less', function () {
    $short = app(LocalLanguageDetector::class)->detect('Красиво!');
    $long = app(LocalLanguageDetector::class)->detect('Очень красивая фотография, мне нравится');

    expect($short->weight)->toBe(7 / 12)
        ->and($long->weight)->toBe(1.0);
});

it('answers only with a language the site installs', function () {
    $detector = new LocalLanguageDetector(array_values(array_diff(supportedLocales(), ['ru'])));

    expect($detector->detect('Очень красивая фотография, мне нравится')?->language)->not->toBe('ru')
        ->and($detector->detect('Wunderschönes Foto, gefällt mir sehr')?->language)->toBe('de');
});
