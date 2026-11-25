<?php

use App\Support\Translations\EnglishChange;

/** A changed sentence as text, the words taken out in [-…-] and put in in {+…+}. */
function englishChangeText(array $parts): string
{
    return implode('', array_map(fn (array $part): string => match ($part['kind']) {
        'removed' => '[-'.$part['text'].'-]',
        'added' => '{+'.$part['text'].'+}',
        default => $part['text'],
    }, $parts));
}

it('marks the words taken out and put in, the sentence shown whole around them', function () {
    $change = EnglishChange::between('Free kittens for adoption.', 'Free puppies and kittens for quick adoption.');

    expect(array_map(englishChangeText(...), $change->sentences))->toBe(['Free {+puppies and +}kittens for {+quick +}adoption.'])
        ->and($change->changed)->toBe(1)
        ->and($change->long)->toBeFalse();
});

it('marks every word a single edit changed, not only the first', function () {
    $change = EnglishChange::between('Vote for the cutest cat today.', 'Pick the funniest dog this week.');

    expect(englishChangeText($change->sentences[0]))->toBe('[-Vote for-]{+Pick+} the [-cutest-]{+funniest+} [-cat-]{+dog+} [-today.-]{+this week.+}');
});

it('lists only the sentences that changed in a long text, says it is long, and counts what it does not list', function () {
    $before = implode(' ', array_map(fn (int $n): string => "Sentence number {$n} stays.", range(1, 12)));
    $after = str_replace(['number 2 stays', 'number 9 stays'], ['number 2 moved', 'number nine stays'], $before)."\nA new closing line.";

    $change = EnglishChange::between($before, $after);

    expect(array_map(englishChangeText(...), $change->sentences))->toBe([
        'Sentence number 2 [-stays.-]{+moved.+}',
        'Sentence number [-9-]{+nine+} stays.',
        '{+A new closing line.+}',
    ])
        ->and($change->long)->toBeTrue()
        ->and($change->unlisted())->toBe(0);

    $many = EnglishChange::between($before, preg_replace('/stays/', 'goes', $before));

    expect($many->changed)->toBe(12)
        ->and($many->sentences)->toHaveCount(EnglishChange::MOST_SHOWN)
        ->and($many->unlisted())->toBe(12 - EnglishChange::MOST_SHOWN);
});

it('marks a sentence taken out whole as taken out', function () {
    $change = EnglishChange::between("First line.\nSecond line.\nThird line.", "First line.\nThird line.");

    expect(array_map(englishChangeText(...), $change->sentences))->toBe(['[-Second line.-]'])
        ->and($change->long)->toBeTrue();
});

it('finds nothing changed in English that only moved its line breaks and spaces around sentences', function () {
    expect(EnglishChange::between('One. Two.', "One.\n\nTwo.  ")->changed)->toBe(0);
});
