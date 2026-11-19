<?php

use App\Enums\TrafficDevice;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Queries\Analytics\TrafficSegmentsQuery;
use App\Support\Analytics\SegmentRules;
use App\Support\Analytics\VisitorFigures;
use Carbon\CarbonImmutable;

/*
 * The segments of a paid source and the people each caught, read straight
 * from the source's clicks of the period on the device chosen: everyone of
 * the source, each segment — a person in two counted in each — and those of
 * no segment, a click without the parameter caught by "is not" and never by
 * "is any of"; people once their page ran, the spend of every click; the
 * people a draft catches; a draft's campaigns only the source's own; and a
 * source's live segments, oldest first. The same on every database engine.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick']);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'external_id' => '881204']);
    $this->other = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'external_id' => '5521904']);
});

function segmentsQuery(): TrafficSegmentsQuery
{
    return app(TrafficSegmentsQuery::class);
}

/** Rules as a segment stores them, read back. */
function segmentRules(array ...$rules): array
{
    return SegmentRules::stored($rules);
}

/** A row's figures as text: visitors, new, starters, accounts, activated, spend. */
function segmentFigures(VisitorFigures $figures): string
{
    return implode(' ', [$figures->visitors, $figures->newVisitors, $figures->starters, $figures->accounts, $figures->activated, $figures->spend]);
}

/**
 * ExoClick's clicks: two people on site 1182 by mobile (one of them twice,
 * one an account), one on site 2210 by desktop who started a game, one with
 * no site at all, a known member on site 1182, and a click on site 1182
 * whose page never ran — ten cents each; plus another source's click and an
 * unpaid visit on site 1182, and an ExoClick click before the period.
 */
function segmentsFixture(TrafficCampaign $campaign, TrafficCampaign $other): void
{
    analyticsClick('ann', $campaign, ['site_ref' => '1182', 'device' => 'mobile', 'spend' => '0.10', 'account_at' => '2026-11-15 11:00:00', 'activated_at' => '2026-11-17 09:00:00']);
    analyticsClick('ann', $campaign, ['site_ref' => '1182', 'device' => 'mobile', 'spend' => '0.10', 'network_day' => '2026-11-16']);
    analyticsClick('bob', $campaign, ['site_ref' => '1182', 'device' => 'mobile', 'spend' => '0.10', 'country' => 'DE']);
    analyticsClick('cat', $other, ['site_ref' => '2210', 'device' => 'desktop', 'spend' => '0.10', 'started_at' => '2026-11-15 10:01:00', 'spend_estimated' => true]);
    analyticsClick('dan', $campaign, ['site_ref' => null, 'device' => 'mobile', 'spend' => '0.10']);
    analyticsClick('member', $campaign, ['site_ref' => '1182', 'device' => 'desktop', 'spend' => '0.10', 'known' => true]);
    analyticsClick('never', $campaign, ['site_ref' => '1182', 'device' => 'mobile', 'spend' => '0.10', 'opened_at' => null]);
    analyticsClick('elsewhere', TrafficCampaign::factory()->create(), ['site_ref' => '1182', 'spend' => '0.10']);
    analyticsVisit('unpaid', ['site_ref' => '1182']);
    analyticsClick('before', $campaign, ['site_ref' => '1182', 'network_day' => '2026-10-01', 'arrived_at' => '2026-10-01 10:00:00', 'spend' => '0.10']);
}

it('counts the source’s people, each segment’s and those of no segment in one read, a person in two segments in each', function () {
    segmentsFixture($this->campaign, $this->other);

    $read = segmentsQuery()->figures($this->exo->id, analyticsFilter(), [
        11 => segmentRules(['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]),
        12 => segmentRules(['parameter' => 'device', 'operator' => 'in', 'values' => ['mobile']]),
        // Every campaign but 881204: a click of no campaign would be in it too.
        13 => segmentRules(['parameter' => 'campaign', 'operator' => 'out', 'values' => [$this->campaign->id]]),
    ]);

    // People once their page ran; every click's spend, its page run or not.
    expect(segmentFigures($read['all']))->toBe('5 4 1 1 1 0.70000000')
        ->and(segmentFigures($read['segments'][11]))->toBe('3 2 0 1 1 0.50000000')
        ->and(segmentFigures($read['segments'][12]))->toBe('3 3 0 1 1 0.50000000')
        ->and(segmentFigures($read['segments'][13]))->toBe('1 1 1 0 0 0.10000000')
        ->and($read['segments'][13]->spendEstimated)->toBe('0.10000000')
        // Nobody of no segment: every click is on site 1182, by mobile or of the other campaign.
        ->and(segmentFigures($read['rest']))->toBe('0 0 0 0 0 0.00000000');
});

it('leaves a click without the parameter out of “is any of”, and in “is not” and the visits of no segment', function () {
    segmentsFixture($this->campaign, $this->other);

    $read = segmentsQuery()->figures($this->exo->id, analyticsFilter(), [
        21 => segmentRules(['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]),
        22 => segmentRules(['parameter' => 'site', 'operator' => 'out', 'values' => ['1182', '2210']], ['parameter' => 'country', 'operator' => 'out', 'values' => ['DE']]),
    ]);

    // ann, bob and the member on 1182; dan, with no site and no country, in neither "is any of" nor out of "is not".
    expect($read['segments'][21]->visitors)->toBe(3)
        ->and($read['segments'][22]->visitors)->toBe(1)
        // cat on 2210.
        ->and(segmentFigures($read['rest']))->toBe('1 1 1 0 0 0.10000000');

    // With no segment, nobody is in none.
    $none = segmentsQuery()->figures($this->exo->id, analyticsFilter(), []);

    expect($none['segments'])->toBe([])
        ->and($none['all']->visitors)->toBe(5)
        ->and($none['rest']->visitors)->toBe(0);
});

it('reads the period and the device chosen', function () {
    segmentsFixture($this->campaign, $this->other);
    $mobile = segmentRules(['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]);

    expect(segmentsQuery()->figures($this->exo->id, analyticsFilter(device: TrafficDevice::Desktop), [1 => $mobile])['segments'][1]->visitors)->toBe(1)
        ->and(segmentsQuery()->figures($this->exo->id, analyticsFilter('90d'), [1 => $mobile])['segments'][1]->visitors)->toBe(4)
        ->and(segmentsQuery()->figures($this->exo->id, analyticsFilter('yesterday'), [1 => $mobile])['all']->visitors)->toBe(0);
});

it('counts the people a draft’s rules catch, and the accounts they made', function () {
    segmentsFixture($this->campaign, $this->other);

    $caught = segmentsQuery()->preview($this->exo->id, analyticsFilter(), segmentRules(
        ['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']],
        ['parameter' => 'device', 'operator' => 'in', 'values' => ['mobile']],
    ));

    expect([$caught->visitors, $caught->newVisitors, $caught->accounts])->toBe([2, 2, 1])
        ->and(segmentsQuery()->preview($this->exo->id, analyticsFilter(), [])->visitors)->toBe(0);
});

it('reads a draft’s campaigns among the source’s own alone, and names campaigns by their ids in the network', function () {
    $foreign = TrafficCampaign::factory()->create(['external_id' => '777']);

    $read = segmentsQuery()->rules($this->exo->id, 'ExoClick', [
        ['parameter' => 'campaign', 'operator' => 'in', 'values' => '881204, 5521904'],
        ['parameter' => 'campaign', 'operator' => 'in', 'values' => '777'],
    ]);

    expect(SegmentRules::store($read['rules']))->toBe([['parameter' => 'campaign', 'operator' => 'in', 'values' => [$this->campaign->id, $this->other->id]]])
        ->and($read['errors'])->toBe([1 => 'ExoClick has no campaign 777.'])
        ->and(segmentsQuery()->campaignRefs([$this->campaign->id, $foreign->id]))->toEqual([$this->campaign->id => '881204', $foreign->id => '777'])
        ->and(segmentsQuery()->campaignRefs([]))->toBe([]);
});

it('lists a source’s segments oldest first, those deleted left out', function () {
    $later = TrafficSegment::factory()->create(['source_id' => $this->exo->id, 'name' => 'Later', 'created_at' => '2026-11-10 10:00:00']);
    TrafficSegment::factory()->create(['source_id' => $this->exo->id, 'name' => 'First', 'created_at' => '2026-11-01 10:00:00']);
    TrafficSegment::factory()->create(['source_id' => $this->exo->id, 'name' => 'Gone', 'deleted_at' => '2026-11-12 10:00:00']);
    TrafficSegment::factory()->create(['name' => 'Elsewhere']);

    expect(segmentsQuery()->of($this->exo->id)->pluck('name')->all())->toBe(['First', 'Later'])
        ->and($later->fresh()->rules)->toBe([['parameter' => 'device', 'operator' => 'in', 'values' => ['mobile']]]);
});
