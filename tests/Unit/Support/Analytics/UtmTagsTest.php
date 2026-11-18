<?php

use App\Support\Analytics\UtmTags;

/*
 * The campaign tags an address carries, as a visit keeps them: the five utm_*
 * keys and no other, trimmed, rid of control characters, at most 120
 * characters however many bytes they take, a network's unfilled placeholder
 * counting as none — and whether an arrival's tags take it out of its
 * session.
 */

it('reads the five tags of an address and nothing else', function () {
    $tags = UtmTags::fromQuery([
        'utm_source' => 'reddit',
        'utm_medium' => 'social',
        'utm_campaign' => 'Spring Sale',
        'utm_content' => 'banner-a',
        'utm_term' => 'taste test',
        'utm_id' => '77',
        'rg_src' => 'exo',
    ]);

    expect($tags->columns())->toBe([
        'utm_source' => 'reddit',
        'utm_medium' => 'social',
        'utm_campaign' => 'Spring Sale',
        'utm_content' => 'banner-a',
        'utm_term' => 'taste test',
    ])
        ->and([$tags->source, $tags->medium, $tags->campaign, $tags->content, $tags->term])->toBe(['reddit', 'social', 'Spring Sale', 'banner-a', 'taste test']);
});

it('trims a tag and takes its control characters out, keeping its case', function (string $value, string $kept) {
    expect(UtmTags::clean($value))->toBe($kept);
})->with([
    'spaces and a tab around it' => ["  Reddit\t", 'Reddit'],
    'a line break inside it' => ["spring\nsale", 'springsale'],
    'a NUL and a DEL' => ["sp\0ri\x7Fng", 'spring'],
    'a control character beyond ASCII' => ["news\u{0085}letter", 'newsletter'],
    'white space beyond ASCII' => ["\u{00A0}promo\u{3000}", 'promo'],
]);

it('keeps at most 120 characters of a tag, cutting between characters, never inside one', function () {
    $cyrillic = UtmTags::clean(str_repeat('я', 130));
    $emoji = UtmTags::clean(str_repeat('🔥', 125));

    expect(mb_strlen((string) $cyrillic))->toBe(120)
        ->and($cyrillic)->toBe(str_repeat('я', 120))
        ->and(mb_check_encoding((string) $cyrillic, 'UTF-8'))->toBeTrue()
        ->and($emoji)->toBe(str_repeat('🔥', 120))
        // A cut that ends on a space is trimmed again.
        ->and(UtmTags::clean(str_repeat('a', 119).' bcd'))->toBe(str_repeat('a', 119))
        ->and(UtmTags::clean(str_repeat('a', 120)))->toBe(str_repeat('a', 120));
});

it('counts a network’s unfilled placeholder as no tag at all', function (string $value) {
    expect(UtmTags::clean($value))->toBeNull()
        ->and(UtmTags::fromQuery(['utm_campaign' => $value])->isEmpty())->toBeTrue();
})->with([
    'braces' => ['{campaign_id}'],
    'brackets' => ['[CAMPAIGN]'],
    'hashes' => ['##ZONE##'],
    'a dollar and braces' => ['${SUBID}'],
    'with spaces around it' => [' {source} '],
    'encoded once more' => ['%7Bcreative%7D'],
]);

it('keeps a tag a placeholder is only part of, as the network wrote it', function () {
    expect(UtmTags::clean('spring_{zone}'))->toBe('spring_{zone}');
});

it('counts a tag that is empty, no text or no UTF-8 as none', function (mixed $value) {
    expect(UtmTags::clean($value))->toBeNull();
})->with([
    'empty' => [''],
    'only spaces' => ['   '],
    'only control characters' => ["\r\n\t"],
    'an array' => [['reddit']],
    'missing' => [null],
    'a number' => [5],
    'broken UTF-8' => ["sale\xFF"],
]);

it('gives the tags there are by their names, which read back the same', function () {
    $tags = UtmTags::fromQuery(['utm_source' => 'newsletter', 'utm_campaign' => 'Weekly 12', 'utm_term' => '']);

    expect($tags->toArray())->toBe(['utm_source' => 'newsletter', 'utm_campaign' => 'Weekly 12'])
        ->and($tags->columns())->toBe(['utm_source' => 'newsletter', 'utm_medium' => null, 'utm_campaign' => 'Weekly 12', 'utm_content' => null, 'utm_term' => null])
        ->and(UtmTags::fromQuery($tags->toArray())->equals($tags))->toBeTrue()
        ->and(UtmTags::fromQuery($tags->columns())->equals($tags))->toBeTrue()
        ->and(UtmTags::fromQuery([])->toArray())->toBe([]);
});

it('names the creative by utm_content, else utm_term', function () {
    expect(UtmTags::fromQuery(['utm_content' => 'video-03', 'utm_term' => 'taste'])->creative())->toBe('video-03')
        ->and(UtmTags::fromQuery(['utm_term' => 'taste'])->creative())->toBe('taste')
        ->and(UtmTags::fromQuery(['utm_source' => 'google'])->creative())->toBeNull();
});

it('tells tags the same only when all five are, case and all', function () {
    $tags = UtmTags::fromQuery(['utm_source' => 'reddit', 'utm_campaign' => 'spring']);

    expect($tags->equals(UtmTags::fromQuery(['utm_source' => ' reddit', 'utm_campaign' => 'spring', 'utm_term' => '{term}'])))->toBeTrue()
        ->and($tags->equals(UtmTags::fromQuery(['utm_source' => 'reddit', 'utm_campaign' => 'Spring'])))->toBeFalse()
        ->and($tags->equals(UtmTags::fromQuery(['utm_source' => 'reddit'])))->toBeFalse()
        ->and($tags->equals(UtmTags::fromQuery([])))->toBeFalse();
});

it('takes an arrival out of its session only by tags of its own other than the session’s', function () {
    $session = UtmTags::fromQuery(['utm_source' => 'reddit', 'utm_campaign' => 'spring']);
    $untagged = UtmTags::fromQuery([]);

    expect(UtmTags::fromQuery(['utm_source' => 'reddit', 'utm_campaign' => 'spring'])->differFrom($session))->toBeFalse()
        ->and(UtmTags::fromQuery(['utm_source' => 'reddit', 'utm_campaign' => 'autumn'])->differFrom($session))->toBeTrue()
        ->and(UtmTags::fromQuery(['utm_source' => 'newsletter'])->differFrom($untagged))->toBeTrue()
        // A link inside the site carries none: the session's tags stay.
        ->and($untagged->differFrom($session))->toBeFalse()
        ->and($untagged->differFrom($untagged))->toBeFalse();
});
