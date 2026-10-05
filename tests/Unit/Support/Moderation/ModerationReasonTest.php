<?php

use App\Support\Moderation\ModerationReason;

/*
 * The one place a moderation reason is reduced to "is there actually a reason
 * here". Unit-level because that question has nothing to do with a database:
 * the Feature tests then assert only the consequence, which is that the
 * reason-required guard does not fire on text an administrator really typed.
 */

it('reduces a reason made only of whitespace to nothing', function (string $reason) {
    expect(ModerationReason::normalize($reason))->toBe('');
})->with([
    'ASCII space' => [' '],
    'tab and newline' => ["\t\n"],
    // trim()'s character list stops here. Everything below survived it.
    'no-break space' => ["\u{00A0}"],
    'ideographic space' => ["\u{3000}"],
    'en quad' => ["\u{2000}"],
    'line separator' => ["\u{2028}"],
    'paragraph separator' => ["\u{2029}"],
    'zero-width no-break space' => ["\u{FEFF}"],
    // Unicode files these two under Cc and Cf rather than Z, so they reach the
    // empty answer by a different route than every space above.
    'next line' => ["\u{0085}"],
    'Mongolian vowel separator' => ["\u{180E}"],
    'one of each' => [" \t\u{00A0}\u{0085}\u{2028}\u{FEFF}\n"],
]);

it('keeps a real reason, stripping only what surrounds it', function (string $reason, string $expected) {
    expect(ModerationReason::normalize($reason))->toBe($expected);
})->with([
    'nothing to strip' => ['Repeated spam', 'Repeated spam'],
    'Unicode space around it' => ["\u{00A0}Repeated spam\u{00A0}", 'Repeated spam'],
    'mixed around it' => [" \u{0085}Repeated spam\u{2028} ", 'Repeated spam'],
    // Interior whitespace is content, whatever kind it is.
    'no-break space inside' => ["Repeated\u{00A0}spam", "Repeated\u{00A0}spam"],
    'newline inside' => ["Two\nlines", "Two\nlines"],
]);

it('returns a reason containing invalid UTF-8 unchanged', function () {
    // preg_replace cannot match against invalid UTF-8: it fails and returns
    // null. Casting that to a string would answer "" — an empty reason — so an
    // administrator who typed a real one would be told a reason is required and
    // what they wrote would be discarded. Returning the input keeps the failure
    // honest: the text is still there, and whatever rejects malformed text
    // rejects it for what it is.
    $reason = "Spam \xC3\x28 from a broken paste";

    expect(ModerationReason::normalize($reason))->toBe($reason)
        ->and(ModerationReason::normalize($reason))->not->toBe('');
});

it('answers an empty string for an empty string', function () {
    expect(ModerationReason::normalize(''))->toBe('');
});
