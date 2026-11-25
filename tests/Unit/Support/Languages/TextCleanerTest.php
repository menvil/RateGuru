<?php

use App\Support\Languages\TextCleaner;

/*
 * What of a text tells its language: its words, without links, handles, tags, digits, emoji and the words people
 * write the same way in many languages.
 */

it('keeps only the words that tell a language', function (string $text, string $cleaned) {
    expect(TextCleaner::clean($text))->toBe($cleaned);
})->with([
    'a plain sentence' => ['Красивая фотография!', 'Красивая фотография'],
    'a link and a handle' => ['Look https://example.com/a?b=c @anna_92 here', 'Look here'],
    'an address and a tag' => ['write me mail@example.com #ootd ok', 'write me'],
    'digits and emoji' => ['10/10 😍🔥 Schön', 'Schön'],
    'words every language shares' => ['wow LOL super Selfie time', 'time'],
    'an apostrophe and a hyphen inside words' => ["it's a well-known c'est", "it's a well-known c'est"],
    'marks inside a word' => ['बहुत सुंदर तस्वीर', 'बहुत सुंदर तस्वीर'],
    'nothing language-bearing' => ['😍 10/10 ok!!! @max.k', ''],
    'a link without a scheme' => ['example.com/private/SecretAbc', ''],
    'a bare domain inside a sentence' => ['Мой сайт anna-photo.de, заходите', 'Мой сайт заходите'],
]);

it('counts the letters of a text', function () {
    expect(TextCleaner::letters('Ab 12 вг!'))->toBe(4)
        ->and(TextCleaner::letters(''))->toBe(0);
});

it('counts a short text for less, a text of twelve letters and more in full', function () {
    expect(TextCleaner::weight('Привет'))->toBe(0.5)
        ->and(TextCleaner::weight('Добрый вечер'))->toBe(0.9166666666666666)
        ->and(TextCleaner::weight('Красивая фотография'))->toBe(1.0)
        ->and(TextCleaner::weight(''))->toBe(0.0);
});
