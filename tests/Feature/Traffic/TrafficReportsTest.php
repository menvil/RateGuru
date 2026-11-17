<?php

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficDimension;
use App\Enums\TrafficSpendOrigin;
use App\Models\Game;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Queries\Traffic\TrafficLogsQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    $this->source = TrafficSource::factory()->create();
    $this->filter = new TrafficReportFilter('2026-11-10', '2026-11-17');
});

/** A visit of the period, on the given network day. */
function reportVisit(TrafficSource $source, array $attributes = []): TrafficVisit
{
    return TrafficVisit::factory()->create(['source_id' => $source->id, 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'opened_at' => '2026-11-15 10:00:00', ...$attributes]);
}

it('adds the period up by device and type, with how its accounts came', function () {
    reportVisit($this->source, ['spend' => '0.10', 'account_at' => now(), 'signup_at' => now(), 'signup_place' => 'post', 'signup_after_seconds' => 900, 'value_real' => '0.5']);
    reportVisit($this->source, ['spend' => '0.10', 'known' => true, 'device' => TrafficDevice::Desktop]);
    reportVisit($this->source, ['spend' => '0.10', 'opened_at' => null]);
    TrafficVisit::factory()->unpaid()->create(['arrived_at' => '2026-11-15 11:00:00', 'network_day' => '2026-11-15', 'opened_at' => now()]);
    // Outside the period: its network day is another, whatever its arrival.
    reportVisit($this->source, ['network_day' => '2026-11-09', 'arrived_at' => '2026-11-10 02:00:00']);

    $rows = collect(app(TrafficReportQuery::class)->summary($this->filter));
    $paidMobile = $rows->first(fn (array $row) => $row['device'] === 'mobile' && $row['channel'] === 'network');

    expect($rows)->toHaveCount(3)
        ->and($paidMobile['figures']->visits)->toBe(2)
        ->and($paidMobile['figures']->opened)->toBe(1)
        ->and($paidMobile['figures']->accounts)->toBe(1)
        ->and($paidMobile['figures']->spend)->toBe('0.20000000')
        ->and($paidMobile['path']['place_post'])->toBe(1)
        ->and($paidMobile['path']['within_an_hour'])->toBe(1)
        ->and($paidMobile['path']['within_10_minutes'])->toBe(0)
        ->and($rows->first(fn (array $row) => $row['device'] === 'desktop')['figures']->knownOpened)->toBe(1);
});

it('splits a level of the tree under its path, biggest first', function () {
    $other = TrafficSource::factory()->create();
    reportVisit($this->source, ['country' => 'US', 'city' => 'Austin', 'account_at' => now()]);
    reportVisit($this->source, ['country' => 'US', 'city' => 'Boston']);
    reportVisit($other, ['country' => 'DE', 'city' => 'Berlin']);

    $sources = app(TrafficReportQuery::class)->level($this->filter, TrafficDimension::Source);
    $cities = app(TrafficReportQuery::class)->level($this->filter->within(TrafficDimension::Source, ['source_id' => $this->source->id, 'channel' => 'network'])->within(TrafficDimension::Country, ['country' => 'US']), TrafficDimension::City);

    expect(array_column(array_column($sources, 'keys'), 'source_id'))->toBe([$this->source->id, $other->id])
        ->and(array_column(array_column($cities, 'keys'), 'city'))->toBe(['Austin', 'Boston']);
});

it('lists the networks’ sites with enough clicks, and the campaign they came through', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z1', 'campaign_id' => $campaign->id]);
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z2', 'campaign_id' => $campaign->id]);
    reportVisit($this->source, ['site_ref' => 's2', 'zone_ref' => 'z3']);

    $sites = app(TrafficReportQuery::class)->zones($this->filter, false, minVisits: 2);
    $zones = app(TrafficReportQuery::class)->zones($this->filter, true);

    expect($sites)->toHaveCount(1)
        ->and($sites[0])->toMatchArray(['site_ref' => 's1', 'campaign_id' => $campaign->id, 'campaigns' => 1])
        ->and($sites[0]['figures']->visits)->toBe(2)
        ->and($zones)->toHaveCount(3);
});

it('adds up campaigns, and each stage of one while it lasted', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    TrafficCampaignStagePeriod::query()->create(['campaign_id' => $campaign->id, 'stage' => TrafficCampaignStage::Test, 'started_at' => '2026-11-14 00:00:00', 'ended_at' => '2026-11-15 12:00:00']);
    TrafficCampaignStagePeriod::query()->create(['campaign_id' => $campaign->id, 'stage' => TrafficCampaignStage::Scale, 'started_at' => '2026-11-15 12:00:00']);
    reportVisit($this->source, ['campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 10:00:00']);
    reportVisit($this->source, ['campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 13:00:00']);
    reportVisit($this->source, ['campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 14:00:00']);

    $stages = app(TrafficReportQuery::class)->stages($campaign->id);

    expect(app(TrafficReportQuery::class)->byCampaign($this->filter)[$campaign->id]->visits)->toBe(3)
        ->and(array_map(fn (array $stage) => [$stage['stage'], $stage['figures']->visits], $stages))->toBe([['test', 1], ['scale', 2]]);
});

it('splits a game’s traffic by type, source, version and device in one pass', function () {
    $game = Game::factory()->create();
    reportVisit($this->source, ['game_id' => $game->id, 'arrived_at' => now()->subDays(2)]);
    reportVisit($this->source, ['game_id' => $game->id, 'arrived_at' => now()->subDay()]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => $game->id, 'arrived_at' => now()->subDay(), 'network_day' => '2026-11-16']);

    $rows = collect(app(TrafficReportQuery::class)->gameBreakdown($game->id, now()->subDays(30)->toImmutable()));
    $paid = $rows->firstWhere('source_id', $this->source->id);

    expect($rows)->toHaveCount(2)
        ->and($paid['figures']->visits)->toBe(2)
        ->and($paid['first_at']->toDateString())->toBe('2026-11-15');
});

it('reconciles a source’s days, and keeps spend no visit took', function () {
    reportVisit($this->source, ['cost' => '0.001', 'spend' => '0.25']);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-15', 'origin' => TrafficSpendOrigin::Manual, 'amount' => '1.00', 'allocated_amount' => '0.25', 'counted' => true, 'allocated_at' => now()]);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-15', 'origin' => TrafficSpendOrigin::Api, 'amount' => '0.90', 'counted' => false, 'allocated_at' => now()]);

    $days = app(TrafficSpendQuery::class)->days($this->source->id, '2026-11-10', '2026-11-17');

    expect($days['2026-11-15'])->toMatchArray(['clicks' => 1, 'costs' => '0.00100000', 'counted' => '0.25000000', 'counted_origin' => 'manual', 'allocated' => true])
        ->and($days['2026-11-15']['figures'])->toBe(['api' => '0.90000000', 'manual' => '1.00000000'])
        ->and(app(TrafficSpendQuery::class)->unallocated('2026-11-10', '2026-11-17'))->toBe([['source_id' => $this->source->id, 'campaign_id' => null, 'amount' => '0.75000000']]);
});

it('pages each log newest first, so rows neither move nor repeat from one page to the next', function (string $log) {
    $make = match ($log) {
        'clicks' => fn () => reportVisit($this->source),
        'milestones' => fn () => TrafficConversion::factory()->create(['visit_id' => reportVisit($this->source)->id]),
        'postbacks' => fn () => TrafficPostback::factory()->create(['conversion_id' => TrafficConversion::factory()->create(['visit_id' => reportVisit($this->source)->id])->id, 'created_at' => now()]),
        'blocks' => fn () => TrafficBlockEvent::query()->create(['source_id' => $this->source->id, 'kind' => 'block', 'level' => TrafficBlockLevel::Site, 'ref' => 's1', 'ref_count' => 1, 'method' => TrafficBlockMethod::Manual, 'actor' => TrafficActor::User, 'ok' => true, 'occurred_at' => now()]),
    };
    $ids = collect(range(1, TrafficLogsQuery::PER_PAGE + 3))->map(fn () => $make()->id)->reverse()->values()->all();
    $page = fn (int $number) => match ($log) {
        'clicks' => app(TrafficLogsQuery::class)->clicks($this->source->id, null, $number),
        'milestones' => app(TrafficLogsQuery::class)->milestones($this->source->id, $number),
        'postbacks' => app(TrafficLogsQuery::class)->postbacks(null, null, $number),
        'blocks' => app(TrafficLogsQuery::class)->blocks($this->source->id, $number),
    };

    $first = $page(1);
    $second = $page(2);

    expect($first->hasMorePages())->toBeTrue()
        ->and([...collect($first->items())->pluck('id')->all(), ...collect($second->items())->pluck('id')->all()])->toBe($ids)
        ->and($second->hasMorePages())->toBeFalse();
})->with(['clicks', 'milestones', 'postbacks', 'blocks']);
