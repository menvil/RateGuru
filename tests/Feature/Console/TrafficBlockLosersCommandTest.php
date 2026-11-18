<?php

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficConnector;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * traffic:block-losers, the block rule run by itself: every five minutes,
 * reading no figures while no source is set to block by itself, recording
 * when it ran for the sources' Blocking pane, and never queueing a site again
 * that is blocked, queued, or unblocked by an administrator lately.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
});

/** A PopAds source blocking sites by itself through a connected, verified API, with so many bot-like clicks per site in the last hour. */
function blockingPopAds(array $clicksBySite, int $cap = 10): TrafficSource
{
    config(['traffic_presets.connectors.popads.verified' => true]);
    $source = TrafficSource::factory()->create(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => true, 'block_daily_cap' => $cap]);
    TrafficApiConnection::factory()->create(['source_id' => $source->id, 'next_sync_at' => now()->addHour()]);

    foreach ($clicksBySite as $site => $clicks) {
        TrafficVisit::factory()->count($clicks)->create(['source_id' => $source->id, 'site_ref' => (string) $site, 'arrived_at' => now()->subHour(), 'network_day' => '2026-11-17', 'opened_at' => null, 'spend' => '0.01']);
    }

    return $source;
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
    $this->artisan('traffic:block-losers')->assertSuccessful()->expectsOutput('Queued 1 blocks.');

    expect(TrafficBlock::query()->where('ref', 's3')->sole()->method)->toBe(TrafficBlockMethod::Auto);
});

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
