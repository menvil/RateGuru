<?php

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficConnector;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficCampaign;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficStepChance;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * traffic:block-losers, the block rule run by itself: every five minutes,
 * reading no figures while no source is set to block by itself, recording
 * when it ran for the sources' Blocking pane, never queueing a site again
 * that is blocked, queued, or unblocked by an administrator lately, and
 * judging each source's sites by its own thresholds, its own average and
 * the target price of an account of their campaign or the source.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
});

/**
 * A PopAds source blocking sites by itself through a connected, verified API,
 * with so many bot-like clicks per site in the last hour — and as many on a
 * sound site, every one opening the page, so the source's own average is
 * one the bots fall far below.
 */
function blockingPopAds(array $clicksBySite, int $cap = 10, array $source = []): TrafficSource
{
    config(['traffic_presets.connectors.popads.verified' => true]);
    // Its sites have a few dozen clicks each: it judges bots from 30, not the general 100.
    $source = TrafficSource::factory()->create(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => true, 'block_daily_cap' => $cap, 'block_bot_clicks' => 30, ...$source]);
    TrafficApiConnection::factory()->create(['source_id' => $source->id, 'next_sync_at' => now()->addHour()]);

    foreach ($clicksBySite as $site => $clicks) {
        TrafficVisit::factory()->count($clicks)->create(['source_id' => $source->id, 'site_ref' => (string) $site, 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => null, 'spend' => '0.01']);
    }

    soundPopAdsClicks($source, array_sum($clicksBySite));

    return $source;
}

/** So many clicks an hour ago on the source's sound site, every one opening the page. */
function soundPopAdsClicks(TrafficSource $source, int $clicks): void
{
    TrafficVisit::factory()->count($clicks)->create(['source_id' => $source->id, 'site_ref' => 'sound', 'arrived_at' => now()->subHour(), 'network_day' => now()->format('Y-m-d'), 'opened_at' => now()->subHour(), 'spend' => '0.01']);
}

it('runs every five minutes in the background, without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'traffic:block-losers'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(60)
        ->and($events->first()->runInBackground)->toBeTrue();
});

it('reads no figures while no source blocks by itself, and records that it ran', function () {
    config(['traffic_presets.connectors.popads.verified' => true]);
    $source = TrafficSource::factory()->create(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => false]);
    TrafficApiConnection::factory()->create(['source_id' => $source->id]);
    TrafficVisit::factory()->count(40)->create(['source_id' => $source->id, 'site_ref' => '40123', 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => null]);

    expect(TrafficSetting::blockRuleRanAt())->toBeNull();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 0 blocks.');
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($queries)->toContain('traffic_sources')
        ->not->toContain('traffic_visits')
        ->not->toContain('traffic_chance_runs')
        ->not->toContain('traffic_step_chances')
        ->not->toContain('traffic_settings')
        ->and(TrafficBlock::query()->count())->toBe(0)
        ->and(TrafficSetting::blockRuleRanAt()?->toIso8601String())->toBe(now()->toIso8601String());

    $this->travel(5)->minutes();
    $this->artisan('traffic:block-losers')->assertSuccessful();

    expect(TrafficSetting::blockRuleRanAt()?->toIso8601String())->toBe(now()->toIso8601String());
});

it('never queues again a site blocked, queued, coming off or unblocked by an administrator lately, run after run', function () {
    $admin = User::factory()->admin()->create();
    $source = blockingPopAds(['s1' => 40, 's2' => 40, 's3' => 40, 's4' => 40, 's5' => 40]);
    TrafficBlock::factory()->create(['source_id' => $source->id, 'level' => TrafficBlockLevel::Site, 'ref' => 's1', 'method' => TrafficBlockMethod::Manual, 'network_state' => TrafficBlockNetworkState::ByHand, 'blocked_at' => now()->subDays(2)]);
    TrafficBlock::factory()->create(['source_id' => $source->id, 'level' => TrafficBlockLevel::Site, 'ref' => 's2', 'method' => TrafficBlockMethod::Api, 'network_state' => TrafficBlockNetworkState::Removing, 'blocked_at' => now()->subDays(2)]);
    // Blocked by hand and unblocked again: the administrator's call, for 30 days.
    $blocking = app(BlockTrafficRefsAction::class);
    $blocking->block($admin, $source, TrafficBlockLevel::Site, ['s3'], null, false);
    $blocking->unblock($admin, TrafficBlock::query()->where('ref', 's3')->sole());

    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 2 blocks.');

    expect(TrafficBlock::query()->where('method', TrafficBlockMethod::Auto)->orderBy('ref')->pluck('ref')->all())->toBe(['s4', 's5'])
        ->and(TrafficBlock::query()->where('ref', 's2')->sole()->network_state)->toBe(TrafficBlockNetworkState::Removing);

    // Five minutes on, the same figures queue nothing more.
    $this->travel(5)->minutes();
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 0 blocks.');

    // After 30 days the unblock no longer holds the rule back: it judges the site again.
    $this->travel(31)->days();
    TrafficVisit::factory()->count(40)->create(['source_id' => $source->id, 'site_ref' => 's3', 'arrived_at' => now()->subHour(), 'network_day' => now()->format('Y-m-d'), 'opened_at' => null, 'spend' => '0.01']);
    soundPopAdsClicks($source, 40);
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 1 blocks.');

    expect(TrafficBlock::query()->where('ref', 's3')->sole()->method)->toBe(TrafficBlockMethod::Auto);
});

it('queues nothing for a source no longer set to block by itself by the time the rule blocks', function (array $since) {
    $source = blockingPopAds([]);
    // Changed after the rule read the source.
    TrafficSource::query()->whereKey($source->id)->update($since);

    expect(app(BlockTrafficRefsAction::class)->block(null, $source, TrafficBlockLevel::Site, ['s1'], null, true, TrafficBlockMethod::Auto, TrafficBlockVerdict::Bots, 'Block rule'))->toBe(0)
        ->and(TrafficBlock::query()->count())->toBe(0)
        ->and(TrafficBlockEvent::query()->count())->toBe(0);
})->with([
    'switched off' => [['block_auto' => false]],
    'paused' => [['status' => 'paused']],
    'blocking zones' => [['block_level' => 'zone']],
]);

it('keeps to the source’s cap for each UTC day, however often it runs', function () {
    $source = blockingPopAds(['s1' => 50, 's2' => 45, 's3' => 40, 's4' => 35], cap: 2);
    // Queued by the rule just after midnight UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 00:05:00'));
    app(BlockTrafficRefsAction::class)->block(null, $source, TrafficBlockLevel::Site, ['earlier'], null, true, TrafficBlockMethod::Auto, TrafficBlockVerdict::Bots, 'Block rule');
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));

    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 1 blocks.');
    $this->travel(5)->minutes();
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 0 blocks.');

    // The biggest spender first; the next UTC day has room again.
    expect(TrafficBlock::query()->where('method', TrafficBlockMethod::Auto)->where('ref', '!=', 'earlier')->pluck('ref')->all())->toBe(['s1']);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 00:02:00'));
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 2 blocks.');
});

it('counts against the day’s cap the blocks it queued, even those an administrator took off again', function () {
    $admin = User::factory()->admin()->create();
    $source = blockingPopAds(['s1' => 50, 's2' => 45, 's3' => 40], cap: 2);

    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 2 blocks.');

    // The administrator unblocks one, and the sync takes it off the network's list and drops it.
    app(BlockTrafficRefsAction::class)->unblock($admin, TrafficBlock::query()->where('ref', 's1')->sole());
    TrafficBlock::query()->where('ref', 's1')->where('network_state', TrafficBlockNetworkState::Removing)->delete();

    $this->travel(5)->minutes();
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 0 blocks.');

    expect(TrafficBlock::query()->where('ref', 's3')->exists())->toBeFalse()
        ->and(TrafficBlock::query()->orderBy('ref')->pluck('ref')->all())->toBe(['s2']);
});

it('judges each source’s sites by its own thresholds and against its own average, so a pop site opening as pop traffic does stays', function () {
    // Pop traffic opening the page 30% of the time: half of that, 15%, calls a site bots — not the rule's 50%.
    $pop = blockingPopAds([]);
    $clicks = fn (TrafficSource $source, string $site, int $clicks, int $opened) => TrafficVisit::factory()->count($clicks)->sequence(fn ($sequence): array => ['opened_at' => $sequence->index < $opened ? now()->subHour() : null])
        ->create(['source_id' => $source->id, 'site_ref' => $site, 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'spend' => '0.01']);
    $clicks($pop, 'pop-ok', 100, 40);
    $clicks($pop, 'pop-low', 40, 10);
    $clicks($pop, 'pop-bots', 40, 4);
    // A source of its own mind: bots only after 60 clicks.
    $patient = blockingPopAds(['p1' => 40], source: ['block_bot_clicks' => 60]);

    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 1 blocks.');

    expect(TrafficBlock::query()->pluck('ref')->all())->toBe(['pop-bots'])
        ->and(TrafficBlock::query()->sole()->verdict)->toBe(TrafficBlockVerdict::Bots);

    // Judged from 30 clicks like the other, the patient source's site is bots too.
    $patient->forceFill(['block_bot_clicks' => 30])->save();
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 1 blocks.');

    expect(TrafficBlock::query()->where('source_id', $patient->id)->pluck('ref')->all())->toBe(['p1']);
});

it('calls a site losing only days after its first click, and once it has spent twice its campaign’s or its source’s target price of an account', function () {
    // The worth stands on 30 accounts.
    $run = TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    TrafficStepChance::query()->forceCreate(['run_id' => $run->id, 'slice_key' => '*', 'step' => 'opened', 'visitors' => 900, 'accounts' => 30, 'slice_accounts' => 30, 'chance' => '0.03333333', 'thin' => false, 'stands_on' => '*']);
    // A source ready to pay $0.50 for an account: a site is judged from $1 spent, far below the rule's $2 or 200 clicks.
    $source = blockingPopAds([], source: ['target_cpa' => '0.50']);
    $cheap = TrafficCampaign::factory()->create(['source_id' => $source->id, 'target_cpa' => '0.30']);
    $other = TrafficCampaign::factory()->create(['source_id' => $source->id]);
    // Ten clicks a site, every one opening the page, half of them days ago and half an hour ago, each worth 30% of its price.
    $losing = function (string $site, int $daysAgo, string $price, string $worth, ?int $campaign = null) use ($source): void {
        foreach ([now()->subDays($daysAgo), now()->subHour()] as $at) {
            TrafficVisit::factory()->count(5)->create(['source_id' => $source->id, 'campaign_id' => $campaign, 'site_ref' => $site, 'arrived_at' => $at, 'network_day' => $at->format('Y-m-d'), 'opened_at' => $at, 'spend' => $price, 'value_real' => $worth]);
        }
    };
    $losing('old', 5, '0.10', '0.03');
    $losing('new', 1, '0.10', '0.03');
    $losing('cheap', 5, '0.08', '0.024');
    // Through a campaign ready to pay only $0.30: judged from $0.60.
    $losing('campaign', 5, '0.08', '0.024', $cheap->id);
    // Through that campaign and another with no target: the site takes its source's $0.50, so it is judged from $1.00, not from $0.60.
    $at = now()->subDays(5);
    TrafficVisit::factory()->count(5)->create(['source_id' => $source->id, 'campaign_id' => $cheap->id, 'site_ref' => 'mixed', 'arrived_at' => $at, 'network_day' => $at->format('Y-m-d'), 'opened_at' => $at, 'spend' => '0.08', 'value_real' => '0.024']);
    $at = now()->subHour();
    TrafficVisit::factory()->count(5)->create(['source_id' => $source->id, 'campaign_id' => $other->id, 'site_ref' => 'mixed', 'arrived_at' => $at, 'network_day' => $at->format('Y-m-d'), 'opened_at' => $at, 'spend' => '0.08', 'value_real' => '0.024']);

    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 2 blocks.');

    expect(TrafficBlock::query()->orderBy('ref')->pluck('ref')->all())->toBe(['campaign', 'old'])
        ->and(TrafficBlock::query()->pluck('verdict')->unique()->all())->toBe([TrafficBlockVerdict::Losing]);

    // Three days after its first click, the new site is judged too.
    $this->travel(2)->days();
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 1 blocks.');

    expect(TrafficBlock::query()->where('ref', 'new')->exists())->toBeTrue()
        ->and(TrafficBlock::query()->where('ref', 'cheap')->exists())->toBeFalse()
        ->and(TrafficBlock::query()->where('ref', 'mixed')->exists())->toBeFalse();
});

it('never blocks a site for paid impressions that mostly never arrive: low reach stays a hint of the report', function () {
    $source = blockingPopAds([]);
    // Yesterday, closed: every paid impression of the sound site arrived, 40 of the thin site's 4,000 — 1%, far below 70% of the source's 2%.
    foreach (['sound' => 40, 'thin' => 4000] as $site => $impressions) {
        paidImpressions($source, '2026-11-16', $impressions, '0.08', ['site_ref' => $site]);
        TrafficVisit::factory()->count(40)->create(['source_id' => $source->id, 'site_ref' => $site, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => '2026-11-16 10:01:00', 'spend' => '0.01']);
    }

    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 0 blocks.');

    expect(TrafficBlock::query()->count())->toBe(0);
});
