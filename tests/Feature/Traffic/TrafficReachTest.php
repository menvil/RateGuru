<?php

use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficApiConnection;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Queries\Traffic\TrafficReachQuery;
use Carbon\CarbonImmutable;

/**
 * How many of the impressions a network billed reached RateGuru
 * (TrafficReachQuery): the visits of each campaign and site the network
 * reported, the narrowest figure first and each visit once, the visits of a
 * slice it did not report on neither side; one origin a source's day, a file
 * with impressions before the API, never a figure that counted clicks; which
 * days are closed; each source's day matched to its own visits as both reads
 * stream by; and the slices folded into what the report shows.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    $this->source = TrafficSource::factory()->create();
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
});

/**
 * The period's slices, each as "day campaign/site/zone origin" => [impressions, reached, opened, started, closed].
 *
 * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: bool}>
 */
function reachRead(?int $sourceId = null, ?int $campaignId = null, string $from = '2026-11-10', string $to = '2026-11-17'): array
{
    $read = [];

    foreach (app(TrafficReachQuery::class)->slices($from, $to, $sourceId, $campaignId) as $slice) {
        $read[$slice['day'].' '.($slice['campaign_id'] ?? '*').'/'.($slice['site_ref'] ?: '*').'/'.($slice['zone_ref'] ?: '*').' '.$slice['origin']] = [
            $slice['figures']->impressions, $slice['figures']->reached, $slice['figures']->opened, $slice['figures']->started, $slice['closed'],
        ];
    }

    ksort($read);

    return $read;
}

it('counts the visits of each campaign and site the network reported, and leaves out those of a slice it did not', function () {
    $other = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    paidImpressions($this->source, '2026-11-15', 100, '0.20', ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-15', 8, 5, 1, ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    // A site, a campaign and a day the network reported nothing for.
    reachedVisits($this->source, '2026-11-15', 3, 3, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '502']);
    reachedVisits($this->source, '2026-11-15', 2, 2, 0, ['campaign_id' => $other->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-14', 4, 4, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);

    $slices = iterator_to_array(app(TrafficReachQuery::class)->slices('2026-11-10', '2026-11-17'), false);

    expect(reachRead())->toBe(['2026-11-15 '.$this->campaign->id.'/501/* api' => [100, 8, 5, 1, true]])
        ->and($slices[0]['figures']->amount)->toBe('0.20000000');
});

it('counts every visit of the campaign that day for a figure with no site, after each site the network reported took its own', function () {
    $other = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    paidImpressions($this->source, '2026-11-15', 50, '0.10', ['campaign_id' => $this->campaign->id]);
    paidImpressions($this->source, '2026-11-15', 20, '0.04', ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-15', 6, 6, 2, ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-15', 4, 1, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '502']);
    reachedVisits($this->source, '2026-11-15', 1, 1, 1, ['campaign_id' => $this->campaign->id]);
    // Another campaign's visits are no figure's.
    reachedVisits($this->source, '2026-11-15', 5, 5, 0, ['campaign_id' => $other->id, 'site_ref' => '502']);

    expect(reachRead())->toBe([
        '2026-11-15 '.$this->campaign->id.'/*/* api' => [50, 5, 2, 1, true],
        '2026-11-15 '.$this->campaign->id.'/501/* api' => [20, 6, 6, 2, true],
    ]);
});

it('counts a day from one origin: a file with impressions before the API, the API where the file has none, never a figure that counted clicks', function () {
    reachedVisits($this->source, '2026-11-15', 3, 0, 0, ['campaign_id' => $this->campaign->id]);
    paidImpressions($this->source, '2026-11-15', 80, '0.16', ['campaign_id' => $this->campaign->id, 'origin' => TrafficSpendOrigin::Csv]);
    paidImpressions($this->source, '2026-11-15', 100, '0.20', ['campaign_id' => $this->campaign->id]);
    // A file of amounts alone, and a typed total, carry no impressions: the API's count.
    paidImpressions($this->source, '2026-11-14', 60, '0.12', ['campaign_id' => $this->campaign->id]);
    paidImpressions($this->source, '2026-11-14', 0, '0.50', ['campaign_id' => $this->campaign->id, 'origin' => TrafficSpendOrigin::Csv, 'reported_impressions' => null]);
    paidImpressions($this->source, '2026-11-13', 40, '0.08', ['campaign_id' => $this->campaign->id]);
    paidImpressions($this->source, '2026-11-13', 0, '1.00', ['origin' => TrafficSpendOrigin::Manual, 'reported_impressions' => null]);
    // Where a figure counts clicks too, an impression may be a view, never meant to arrive; none counted is no such figure.
    paidImpressions($this->source, '2026-11-12', 1000, '0.30', ['campaign_id' => $this->campaign->id, 'zone_ref' => '9001', 'reported_clicks' => 12]);
    paidImpressions($this->source, '2026-11-12', 30, '0.06', ['campaign_id' => $this->campaign->id, 'zone_ref' => '9002', 'reported_clicks' => 0]);

    expect(reachRead())->toBe([
        '2026-11-12 '.$this->campaign->id.'/*/9002 api' => [30, 0, 0, 0, true],
        '2026-11-13 '.$this->campaign->id.'/*/* api' => [40, 0, 0, 0, true],
        '2026-11-14 '.$this->campaign->id.'/*/* api' => [60, 0, 0, 0, true],
        '2026-11-15 '.$this->campaign->id.'/*/* csv' => [80, 3, 0, 0, true],
    ]);
});

it('says which days are closed: the API’s once the sync closed them while connected, any other once the day is over', function () {
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id, 'spend_closed_through' => '2026-11-15']);
    $file = TrafficSource::factory()->create();
    $gone = TrafficSource::factory()->create();
    TrafficApiConnection::factory()->create(['source_id' => $gone->id, 'status' => TrafficConnectionStatus::Disconnected, 'credentials' => null, 'spend_closed_through' => '2026-11-10']);
    $east = TrafficSource::factory()->create(['reporting_timezone' => 'Pacific/Kiritimati']);

    foreach (['2026-11-15', '2026-11-16', '2026-11-17'] as $day) {
        paidImpressions($this->source, $day, 10, '0.02');
    }

    paidImpressions($file, '2026-11-16', 10, '0.02', ['origin' => TrafficSpendOrigin::Csv]);
    paidImpressions($file, '2026-11-17', 10, '0.02', ['origin' => TrafficSpendOrigin::Csv]);
    paidImpressions($gone, '2026-11-16', 10, '0.02');
    // Already the 18th at UTC+14: its 17th is over.
    paidImpressions($east, '2026-11-17', 10, '0.02', ['origin' => TrafficSpendOrigin::Csv]);

    $closed = collect(app(TrafficReachQuery::class)->slices('2026-11-10', '2026-11-17'))
        ->mapWithKeys(fn (array $slice): array => [$slice['source_id'].' '.$slice['day'] => $slice['closed']])
        ->all();

    expect($closed)->toBe([
        $this->source->id.' 2026-11-15' => true,
        $this->source->id.' 2026-11-16' => false,
        $this->source->id.' 2026-11-17' => false,
        $file->id.' 2026-11-16' => true,
        $file->id.' 2026-11-17' => false,
        $gone->id.' 2026-11-16' => true,
        $east->id.' 2026-11-17' => true,
    ]);
});

it('narrows to a source and a campaign, the day’s origin chosen over all its campaigns', function () {
    $other = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    $elsewhere = TrafficSource::factory()->create();
    paidImpressions($this->source, '2026-11-15', 30, '0.06', ['campaign_id' => $this->campaign->id, 'site_ref' => '501', 'origin' => TrafficSpendOrigin::Csv]);
    paidImpressions($this->source, '2026-11-15', 70, '0.14', ['campaign_id' => $other->id, 'site_ref' => '501']);
    paidImpressions($this->source, '2026-11-16', 70, '0.14', ['campaign_id' => $other->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-15', 2, 1, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-15', 5, 0, 0, ['campaign_id' => $other->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-16', 6, 0, 0, ['campaign_id' => $other->id, 'site_ref' => '501']);
    paidImpressions($elsewhere, '2026-11-15', 500, '1.00');

    // The 15th is counted from the file, which has only the first campaign: the other's API figure for it never counts.
    expect(reachRead($this->source->id))->toBe([
        '2026-11-15 '.$this->campaign->id.'/501/* csv' => [30, 2, 1, 0, true],
        '2026-11-16 '.$other->id.'/501/* api' => [70, 6, 0, 0, true],
    ])
        ->and(reachRead($this->source->id, $other->id))->toBe(['2026-11-16 '.$other->id.'/501/* api' => [70, 6, 0, 0, true]])
        ->and(reachRead(campaignId: $this->campaign->id))->toBe(['2026-11-15 '.$this->campaign->id.'/501/* csv' => [30, 2, 1, 0, true]])
        ->and(reachRead(from: '2026-11-16', to: '2026-11-16'))->toBe(['2026-11-16 '.$other->id.'/501/* api' => [70, 6, 0, 0, true]])
        ->and(reachRead())->toHaveKey('2026-11-15 */*/* api');
});

it('matches a zone the network reported before its site, and reads nothing without impressions', function () {
    expect(iterator_to_array(app(TrafficReachQuery::class)->slices('2026-11-10', '2026-11-17'), false))->toBe([]);

    paidImpressions($this->source, '2026-11-15', 40, '0.08', ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    paidImpressions($this->source, '2026-11-15', 25, '0.05', ['campaign_id' => $this->campaign->id, 'site_ref' => '501', 'zone_ref' => '77']);
    reachedVisits($this->source, '2026-11-15', 3, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '501', 'zone_ref' => '77']);
    reachedVisits($this->source, '2026-11-15', 4, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '501', 'zone_ref' => '78']);

    expect(reachRead())->toBe([
        '2026-11-15 '.$this->campaign->id.'/501/* api' => [40, 4, 0, 0, true],
        '2026-11-15 '.$this->campaign->id.'/501/77 api' => [25, 3, 0, 0, true],
    ]);
});

it('matches each source’s day to its own visits as both reads stream by, past the days and sources the network reported nothing for', function () {
    $second = TrafficSource::factory()->create();
    $third = TrafficSource::factory()->create();
    $campaign = TrafficCampaign::factory()->create(['source_id' => $second->id]);
    // Visits before, between and after the days with figures, and of a source with none.
    reachedVisits($this->source, '2026-11-11', 9, 9, 9, ['campaign_id' => $this->campaign->id]);
    reachedVisits($this->source, '2026-11-12', 2, 1, 0, ['campaign_id' => $this->campaign->id]);
    reachedVisits($this->source, '2026-11-13', 7, 7, 7, ['campaign_id' => $this->campaign->id]);
    reachedVisits($this->source, '2026-11-14', 3, 2, 1, ['campaign_id' => $this->campaign->id]);
    reachedVisits($second, '2026-11-12', 4, 4, 0, ['campaign_id' => $campaign->id, 'site_ref' => '0']);
    reachedVisits($second, '2026-11-16', 6, 6, 6, ['campaign_id' => $campaign->id]);
    reachedVisits($third, '2026-11-12', 5, 5, 5);
    paidImpressions($this->source, '2026-11-12', 10, '0.02', ['campaign_id' => $this->campaign->id]);
    paidImpressions($this->source, '2026-11-14', 10, '0.02', ['campaign_id' => $this->campaign->id]);
    // A site called "0" is a site like any other.
    paidImpressions($second, '2026-11-12', 10, '0.02', ['campaign_id' => $campaign->id, 'site_ref' => '0']);
    paidImpressions($second, '2026-11-15', 10, '0.02', ['campaign_id' => $campaign->id]);

    $read = collect(app(TrafficReachQuery::class)->slices('2026-11-10', '2026-11-17'))
        ->map(fn (array $slice): array => [$slice['source_id'], $slice['day'], $slice['site_ref'], $slice['figures']->reached, $slice['figures']->opened, $slice['figures']->started])
        ->all();

    expect($read)->toBe([
        [$this->source->id, '2026-11-12', '', 2, 1, 0],
        [$this->source->id, '2026-11-14', '', 3, 2, 1],
        [$second->id, '2026-11-12', '0', 4, 4, 0],
        [$second->id, '2026-11-15', '', 0, 0, 0],
    ]);
});

it('says whether a figure is one the visits’ spend was shared out from', function () {
    paidImpressions($this->source, '2026-11-15', 40, '0.08', ['campaign_id' => $this->campaign->id, 'site_ref' => '501', 'counted' => true]);
    paidImpressions($this->source, '2026-11-15', 30, '0.06', ['campaign_id' => $this->campaign->id, 'site_ref' => '502']);

    $counted = collect(app(TrafficReachQuery::class)->slices('2026-11-10', '2026-11-17'))
        ->mapWithKeys(fn (array $slice): array => [$slice['site_ref'] => $slice['counted']])
        ->all();

    expect($counted)->toBe(['501' => true, '502' => false]);
});

it('folds the slices into what the report shows: by source and campaign, each site or zone over its closed days and the others, each day, and each source’s closed days', function () {
    $other = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    $second = TrafficSource::factory()->create();
    // Closed days.
    paidImpressions($this->source, '2026-11-15', 100, '0.20', ['campaign_id' => $this->campaign->id, 'site_ref' => '501', 'counted' => true]);
    reachedVisits($this->source, '2026-11-15', 60, 30, 3, ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    paidImpressions($this->source, '2026-11-15', 50, '0.10', ['campaign_id' => $other->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-15', 10, 5, 1, ['campaign_id' => $other->id, 'site_ref' => '501']);
    paidImpressions($this->source, '2026-11-16', 20, '0.04', ['campaign_id' => $this->campaign->id, 'zone_ref' => '77']);
    reachedVisits($this->source, '2026-11-16', 15, 0, 0, ['campaign_id' => $this->campaign->id, 'zone_ref' => '77']);
    // A campaign's figure with no site: in the sums, at no site.
    paidImpressions($this->source, '2026-11-16', 30, '0.06', ['campaign_id' => $other->id]);
    // Today, not closed.
    paidImpressions($this->source, '2026-11-17', 10, '0.02', ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    reachedVisits($this->source, '2026-11-17', 9, 9, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '501']);
    paidImpressions($second, '2026-11-15', 40, '0.08', ['site_ref' => '601']);
    reachedVisits($second, '2026-11-15', 20, 20, 2, ['site_ref' => '601']);

    $tally = app(TrafficReachQuery::class)->tally('2026-11-10', '2026-11-17');
    $figures = fn ($reach): array => [$reach->impressions, $reach->amount, $reach->reached, $reach->opened, $reach->started];
    $site = $this->source->id.':site:501';

    expect($figures($tally->total()))->toBe([250, '0.50000000', 114, 64, 6])
        ->and($figures($tally->total($this->source->id)))->toBe([210, '0.42000000', 94, 44, 4])
        ->and($figures($tally->total($this->source->id, $other->id)))->toBe([80, '0.16000000', 10, 5, 1])
        ->and($figures($tally->total($second->id, $this->campaign->id)))->toBe([0, '0', 0, 0, 0])
        ->and($tally->sources())->toBe([$this->source->id, $second->id])
        ->and($tally->sources(campaignId: $other->id))->toBe([$this->source->id])
        ->and($tally->openDays())->toBe([$this->source->id => ['2026-11-17']])
        ->and($tally->openDays(campaignId: $other->id))->toBe([])
        ->and(array_map($figures, $tally->places($this->source->id)))->toBe([
            $site => [160, '0.32000000', 79, 44, 4],
            $this->source->id.':zone:77' => [20, '0.04000000', 15, 0, 0],
        ])
        ->and(array_map($figures, $tally->places($this->source->id, $this->campaign->id, closedOnly: true)))->toBe([
            $site => [100, '0.20000000', 60, 30, 3],
            $this->source->id.':zone:77' => [20, '0.04000000', 15, 0, 0],
        ])
        ->and(array_keys($tally->places()))->toBe([$site, $this->source->id.':zone:77', $second->id.':site:601'])
        ->and($tally->placesInSpend($this->source->id))->toBe([$site => true])
        ->and($tally->placesInSpend($this->source->id, $other->id))->toBe([])
        ->and(array_map($figures, $tally->closedBySource()))->toBe([
            $this->source->id => [200, '0.40000000', 85, 35, 4],
            $second->id => [40, '0.08000000', 20, 20, 2],
        ])
        ->and(array_map($figures, $tally->closedBySource($second->id)))->toBe([$second->id => [40, '0.08000000', 20, 20, 2]])
        ->and(array_map($figures, $tally->days($this->source->id)))->toBe([
            '2026-11-15' => [150, '0.30000000', 70, 35, 4],
            '2026-11-16' => [50, '0.10000000', 15, 0, 0],
            '2026-11-17' => [10, '0.02000000', 9, 9, 0],
        ])
        ->and($tally->days(999999))->toBe([]);
});
