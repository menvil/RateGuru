<?php

use App\Enums\SegmentOperator;
use App\Enums\SegmentParameter;
use App\Enums\SegmentVerdict;
use App\Support\Analytics\SegmentRules;
use App\Support\Analytics\VisitorFigures;

/*
 * The conditions of a paid source's segment: values typed split at commas and
 * line breaks, each once; a draft read into rules with what is wrong with each
 * condition by its place — a device, country or campaign there is no such,
 * none, too many, too long; rules stored and read back, anything malformed
 * left out; the SQL a visit matches, never unknown, every value bound; the
 * chips a row says them by; the draft they go back to; and the verdict of a
 * segment against its source.
 */

const SEGMENT_COUNTRIES = ['DE', 'AT', 'US'];

/** A draft condition as the drawer holds it. */
function segmentCondition(string $parameter, string $values, string $operator = 'in'): array
{
    return ['parameter' => $parameter, 'operator' => $operator, 'values' => $values];
}

/** A draft read with the source's campaigns 881204 and 5521904. */
function readSegmentDraft(array $draft): array
{
    return SegmentRules::read($draft, SEGMENT_COUNTRIES, ['881204' => 7, '5521904' => 9], 'ExoClick');
}

it('splits the values typed at commas and line breaks, trimmed, without control characters, each once', function () {
    expect(SegmentRules::values(" 1182, 2210 ,\n3300\t1182,, "))->toBe(['1182', '2210', '3300'])
        ->and(SegmentRules::values("bad\x01id, "))->toBe(['badid'])
        ->and(SegmentRules::values(''))->toBe([]);
});

it('reads a draft into rules, a device and a country as stored and a campaign as the source’s own id', function () {
    $read = readSegmentDraft([
        segmentCondition('site', '1182, 2210'),
        segmentCondition('device', 'Mobile', 'out'),
        segmentCondition('country', 'de, AT'),
        segmentCondition('campaign', '5521904, 881204, 5521904'),
        segmentCondition('creative', 'banner-1'),
    ]);

    expect($read['errors'])->toBe([])
        ->and(SegmentRules::store($read['rules']))->toBe([
            ['parameter' => 'site', 'operator' => 'in', 'values' => ['1182', '2210']],
            ['parameter' => 'device', 'operator' => 'out', 'values' => ['mobile']],
            ['parameter' => 'country', 'operator' => 'in', 'values' => ['DE', 'AT']],
            ['parameter' => 'campaign', 'operator' => 'in', 'values' => [9, 7]],
            ['parameter' => 'creative', 'operator' => 'in', 'values' => ['banner-1']],
        ])
        ->and(SegmentRules::campaignRefs([segmentCondition('campaign', '1, 2'), segmentCondition('site', '3'), segmentCondition('campaign', '2, 4')]))->toBe(['1', '2', '4']);
});

it('says what is wrong with each condition by its place, leaving it out of the rules', function () {
    $read = readSegmentDraft([
        segmentCondition('site', ''),
        segmentCondition('device', ''),
        segmentCondition('device', 'tablet'),
        segmentCondition('country', 'DE, XX'),
        segmentCondition('campaign', '123, 881204, 456'),
        segmentCondition('campaign', '999'),
        segmentCondition('zone', implode(',', range(1, SegmentRules::MOST_VALUES + 1))),
        segmentCondition('creative', str_repeat('x', SegmentRules::MAX_LENGTH + 1)),
        segmentCondition('city', 'Berlin'),
        segmentCondition('site', '1', 'between'),
        segmentCondition('zone', '44718'),
    ]);

    expect($read['errors'])->toBe([
        0 => 'Type at least one value.',
        1 => 'Pick at least one device.',
        2 => 'Pick Mobile, Desktop or both.',
        3 => 'No country has the code XX.',
        4 => 'ExoClick has no campaigns 123, 456.',
        5 => 'ExoClick has no campaign 999.',
        6 => 'Up to 25 values in one condition.',
        7 => 'Each value is up to 120 characters.',
        8 => 'Choose what this condition reads.',
        9 => 'Choose “is any of” or “is not”.',
    ])
        ->and(SegmentRules::store($read['rules']))->toBe([['parameter' => 'zone', 'operator' => 'in', 'values' => ['44718']]]);
});

it('reads stored rules back, leaving out anything that is no rule', function () {
    expect(SegmentRules::store(SegmentRules::stored([
        ['parameter' => 'campaign', 'operator' => 'out', 'values' => [7, '9', 'x', 7]],
        ['parameter' => 'site', 'operator' => 'in', 'values' => ['1182', '', 5]],
        ['parameter' => 'city', 'operator' => 'in', 'values' => ['Berlin']],
        ['parameter' => 'zone', 'operator' => 'in', 'values' => []],
        'nothing',
    ])))->toBe([
        ['parameter' => 'campaign', 'operator' => 'out', 'values' => [7, 9]],
        ['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']],
    ])
        ->and(SegmentRules::stored(null))->toBe([])
        ->and(SegmentRules::stored('[]'))->toBe([]);
});

it('writes the SQL a visit matches, every value bound, a missing parameter caught by “is not” and never by “is any of”', function () {
    $rules = SegmentRules::stored([
        ['parameter' => 'site', 'operator' => 'in', 'values' => ['1182', '2210']],
        ['parameter' => 'device', 'operator' => 'out', 'values' => ['desktop']],
        ['parameter' => 'campaign', 'operator' => 'in', 'values' => [7]],
    ]);

    expect(SegmentRules::predicate($rules))->toBe([
        '((site_ref IS NOT NULL AND site_ref IN (?, ?)) AND (device IS NULL OR device NOT IN (?)) AND (campaign_id IS NOT NULL AND campaign_id IN (?)))',
        ['1182', '2210', 'desktop', 7],
    ])
        // No condition matches nothing.
        ->and(SegmentRules::predicate([]))->toBe(['1 = 0', []])
        ->and(SegmentRules::predicate([['parameter' => SegmentParameter::Zone, 'operator' => SegmentOperator::In, 'values' => []]]))->toBe(['(1 = 0)', []]);
});

it('says each condition on a row: is, is any of, is not — a device by its label, a campaign by its id in the network, the rest counted', function () {
    $rules = SegmentRules::stored([
        ['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']],
        ['parameter' => 'country', 'operator' => 'in', 'values' => ['DE', 'AT']],
        ['parameter' => 'device', 'operator' => 'out', 'values' => ['desktop']],
        ['parameter' => 'campaign', 'operator' => 'in', 'values' => [7, 8]],
        ['parameter' => 'zone', 'operator' => 'in', 'values' => ['1', '2', '3', '4', '5']],
    ]);

    expect(SegmentRules::chips($rules, [7 => '881204']))->toBe([
        'Site is 1182',
        'Country is any of DE, AT',
        'Device is not Desktop',
        'Campaign is any of 881204, #8',
        'Zone is any of 1, 2, 3 and 2 more',
    ])
        ->and(SegmentRules::campaignIds($rules))->toBe([7, 8])
        ->and(SegmentRules::draft($rules, [7 => '881204', 8 => '5521904']))->toBe([
            segmentCondition('site', '1182'),
            segmentCondition('country', 'DE, AT'),
            segmentCondition('device', 'desktop', 'out'),
            segmentCondition('campaign', '881204, 5521904'),
            segmentCondition('zone', '1, 2, 3, 4, 5'),
        ]);
});

it('judges a segment against its source by accounts per new visitor, with too little data below the denominators', function (int $newVisitors, int $accounts, int $sourceAccounts, SegmentVerdict $verdict) {
    // The source: 1,000 new visitors, its accounts as given — 40 makes 4% the rate to compare with.
    $source = new VisitorFigures(visitors: 1000, newVisitors: 1000, accounts: $sourceAccounts);
    $segment = new VisitorFigures(visitors: $newVisitors + 10, newVisitors: $newVisitors, accounts: $accounts);

    expect(SegmentRules::verdict($segment, $source, 100, 10))->toBe($verdict)
        ->and($verdict->label())->toBeString();
})->with([
    'strong at 1.25 times the source exactly' => [200, 10, 40, SegmentVerdict::Strong],
    'average just under' => [200, 9, 40, SegmentVerdict::Average],
    'a block candidate at half exactly' => [200, 4, 40, SegmentVerdict::Block],
    'average just over half' => [200, 5, 40, SegmentVerdict::Average],
    'too few new visitors for a rate' => [99, 50, 40, SegmentVerdict::TooLittle],
    'a source with too few accounts' => [200, 10, 9, SegmentVerdict::TooLittle],
]);
