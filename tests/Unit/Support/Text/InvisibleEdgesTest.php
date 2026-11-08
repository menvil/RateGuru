<?php

use App\Support\Text\InvisibleEdges;

$scotland = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";

it('strips what renders as nothing at either end, and keeps what it must', function (string $text, string $stripped) {
    expect(InvisibleEdges::strip($text))->toBe($stripped);
})->with([
    'spaces' => ['  hello  ', 'hello'],
    'a no-break space, a zero-width space and a byte-order mark' => ["\u{00A0}\u{200B}hello\u{FEFF}", 'hello'],
    'nothing but invisible characters, direction marks and tags among them' => ["\u{200B}\u{200F} \u{E0067}\u{FEFF}", ''],
    'a subdivision flag at the end' => ["go {$scotland}", "go {$scotland}"],
    'a subdivision flag, then spaces' => ["go {$scotland}  ", "go {$scotland}"],
    'a tag character that ends no flag' => ["ab\u{E0067}", 'ab'],
    'a tag character that ends no flag, then a space' => ["ab\u{E0067} ", 'ab'],
    'tags after a flag cut short' => ["ab\u{1F3F4}\u{E0067}\u{E0062}", "ab\u{1F3F4}"],
    'a right-to-left mark after the last punctuation' => ["שלום!\u{200F}", "שלום!\u{200F}"],
    'a right-to-left mark, then a space' => ["שלום!\u{200F} ", "שלום!\u{200F}"],
    'a right-to-left mark at the start' => ["\u{200F}שלום", "\u{200F}שלום"],
    'an Arabic letter mark at the end' => ["مرحبا!\u{061C}", "مرحبا!\u{061C}"],
    'a zero-width space before a closing mark' => ["hello\u{200B}\u{200F}", "hello\u{200F}"],
    'a zero-width space after an opening mark' => ["\u{200F}\u{200B}שלום", "\u{200F}שלום"],
    'a stray tag before a closing mark' => ["ab\u{E0067}\u{200F}", "ab\u{200F}"],
    'a subdivision flag before a closing mark' => ["go {$scotland}\u{200F}", "go {$scotland}\u{200F}"],
    'a zero-width space before a stray tag' => ["ab\u{200B}\u{E0067}", 'ab'],
]);

it('leaves malformed text as it is, for whatever rejects it to say so', function () {
    expect(InvisibleEdges::strip("\xFF bad"))->toBe("\xFF bad");
});
