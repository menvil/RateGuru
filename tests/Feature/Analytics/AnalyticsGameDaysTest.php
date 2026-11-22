<?php

use App\Actions\Analytics\RefreshAnalyticsDayAction;
use App\Actions\Analytics\RefreshAnalyticsVisitsAction;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficKnownReason;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsGameDay;
use App\Models\AnalyticsRollupState;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Queries\Traffic\TrafficReportQuery;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The sums of each day's games' visits (analytics_game_days): written with
 * each network day's rows, on every engine, for every UTC day its visits
 * arrived on, by country and with every country together; read by a game's
 * figures for their whole days, the visits of the part days at the ends of
 * a stretch beside them — the same figures as the rows give for every
 * stretch, filter, version and moment a sign-up's window to confirm closes.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
});

/**
 * A game's visits over a week: paid and free, two versions, both devices,
 * two countries and none, a network day that began the UTC day before,
 * sign-ups confirmed fast, slowly, with no time recorded and not at all —
 * one long enough ago that its window has closed — a visitor signed in when
 * they came, one today, and another game's.
 *
 * @return array{game: Game, versions: array{0: GameVersion, 1: GameVersion}}
 */
function gameDaysFixture(): array
{
    $game = Game::factory()->create();
    $versions = [GameVersion::factory()->create(['game_id' => $game->id, 'number' => 1]), GameVersion::factory()->create(['game_id' => $game->id, 'number' => 2])];
    $source = TrafficSource::factory()->create();
    $campaigns = [TrafficCampaign::factory()->create(['source_id' => $source->id]), TrafficCampaign::factory()->create(['source_id' => $source->id])];
    $paid = fn (int $campaign): array => ['source_id' => $source->id, 'campaign_id' => $campaigns[$campaign]->id, 'channel' => TrafficChannel::Network];
    $visits = [
        ['2026-11-09 10:00:00', '2026-11-09', [...$paid(0), 'version_id' => $versions[0]->id, 'country' => 'DE', 'signup_at' => '2026-11-09 10:03:00', 'spend' => '0.10']],
        ['2026-11-10 10:00:00', '2026-11-10', [...$paid(0), 'version_id' => $versions[0]->id, 'country' => 'DE', 'started_at' => '2026-11-10 10:01:00', 'completed_at' => '2026-11-10 10:04:00', 'goal_at' => '2026-11-10 10:04:00', 'signup_at' => '2026-11-10 10:05:00', 'account_at' => '2026-11-10 10:09:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 540, 'value_real' => '0.5', 'spend' => '0.12', 'spend_estimated' => true]],
        ['2026-11-12 23:30:00', '2026-11-13', ['channel' => TrafficChannel::Search, 'version_id' => $versions[1]->id, 'device' => TrafficDevice::Desktop, 'country' => 'FR', 'started_at' => '2026-11-12 23:31:00']],
        ['2026-11-13 08:00:00', '2026-11-13', [...$paid(1), 'version_id' => $versions[0]->id, 'country' => null, 'signup_at' => '2026-11-13 08:05:00', 'account_at' => '2026-11-13 09:00:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 3600, 'value_expected' => '0.25', 'spend' => '0.11']],
        ['2026-11-14 08:00:00', '2026-11-14', [...$paid(1), 'version_id' => $versions[1]->id, 'country' => 'DE', 'signup_at' => '2026-11-14 08:05:00', 'spend' => '0.09']],
        ['2026-11-15 12:00:00', '2026-11-15', [...$paid(0), 'version_id' => $versions[1]->id, 'country' => 'FR', 'known' => true, 'known_reason' => TrafficKnownReason::SignedIn, 'signup_at' => '2026-11-15 12:01:00', 'account_at' => '2026-11-15 12:02:00', 'spend' => '0.13']],
        ['2026-11-16 15:00:00', '2026-11-16', [...$paid(0), 'version_id' => $versions[0]->id, 'device' => TrafficDevice::Desktop, 'country' => 'DE', 'signup_at' => '2026-11-16 15:05:00', 'account_at' => '2026-11-17 16:00:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 90000, 'post_published_at' => '2026-11-17 10:00:00', 'returned_at' => '2026-11-17 09:00:00']],
        ['2026-11-17 09:00:00', '2026-11-17', [...$paid(1), 'version_id' => $versions[1]->id, 'country' => 'FR', 'signup_at' => '2026-11-17 09:10:00', 'account_at' => '2026-11-17 09:15:00', 'spend' => '0.07']],
        ['2026-11-17 10:00:00', '2026-11-17', [...$paid(0), 'version_id' => $versions[0]->id, 'country' => 'DE', 'opened_at' => null, 'spend' => '0.05']],
    ];

    foreach ($visits as [$at, $day, $attributes]) {
        TrafficVisit::factory()->create(['game_id' => $game->id, 'arrived_at' => $at, 'network_day' => $day, 'opened_at' => $at, 'device' => TrafficDevice::Mobile, ...$attributes]);
    }

    // Another game's visit counts in none of this one's figures.
    TrafficVisit::factory()->create(['game_id' => Game::factory(), 'source_id' => $source->id, 'arrived_at' => '2026-11-14 10:00:00', 'network_day' => '2026-11-14', 'country' => 'BR']);

    return ['game' => $game, 'versions' => $versions];
}

it('writes a network day’s sums for every UTC day its visits arrived on, by country and with every country together', function () {
    ['game' => $game] = gameDaysFixture();
    fillAnalyticsRollup();

    expect(app(AnalyticsRollupGate::class)->readsGameDays())->toBeTrue()
        ->and(AnalyticsGameDay::query()->where('network_day', '2026-11-13')->where('level', AnalyticsGameDay::BY_COUNTRY)->where('game_id', $game->id)->orderBy('arrival_day')->pluck('arrival_day')->all())->toBe(['2026-11-12', '2026-11-13'])
        ->and((int) AnalyticsGameDay::query()->where('level', AnalyticsGameDay::ALL)->where('game_id', $game->id)->sum('visits'))->toBe(9)
        ->and((int) AnalyticsGameDay::query()->where('level', AnalyticsGameDay::BY_COUNTRY)->where('game_id', $game->id)->sum('visits'))->toBe(9)
        ->and(AnalyticsGameDay::query()->where('level', AnalyticsGameDay::ALL)->whereNotNull('country')->exists())->toBeFalse()
        ->and(AnalyticsRollupState::current()->game_days_filled_at)->not->toBeNull();
});

it('gives a game’s breakdown the rows give over whole days, part days at either end, for ever after, within a day and over no time at all', function (string $since, ?string $until) {
    ['game' => $game] = gameDaysFixture();

    $rows = readBothWays(TrafficReportQuery::class)->gameBreakdown($game->id, CarbonImmutable::parse($since), $until === null ? null : CarbonImmutable::parse($until));

    expect($rows)->toBeArray();
})->with([
    'whole days' => ['2026-11-10 00:00:00', '2026-11-16 00:00:00'],
    'up to now' => ['2026-10-18 00:00:00', '2026-11-17 12:00:01'],
    'part days at both ends' => ['2026-11-12 13:37:11', '2026-11-16 09:12:45'],
    'for ever after a moment' => ['2026-11-10 08:00:00', null],
    'within today' => ['2026-11-17 00:00:00', '2026-11-17 12:00:01'],
    'two part days, no whole one' => ['2026-11-12 20:00:00', '2026-11-13 09:00:00'],
    'no time at all' => ['2026-11-13 08:00:00', '2026-11-13 08:00:00'],
]);

it('narrows a game’s breakdown, campaigns and sign-ups to a device, countries or every other country the way the rows do', function (?VisitFilter $filter) {
    ['game' => $game] = gameDaysFixture();
    $since = CarbonImmutable::parse('2026-10-18 00:00:00');
    $until = CarbonImmutable::parse('2026-11-17 12:00:01');

    readBothWays(TrafficReportQuery::class)->gameBreakdown($game->id, $since, $until, $filter);
    readBothWays(TrafficReportQuery::class)->gameCampaigns($game->id, $since, $until, $filter);
    $signups = readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until, visits: $filter);

    expect($signups['visits'])->toBeGreaterThanOrEqual(0);
})->with([
    'every visit' => [null],
    'one device' => [new VisitFilter(TrafficDevice::Desktop)],
    'some countries' => [new VisitFilter(countries: ['DE', 'FR'])],
    'a device in a country' => [new VisitFilter(TrafficDevice::Mobile, ['DE'])],
    'every other country, and none known' => [new VisitFilter(countries: ['DE'], otherCountries: true)],
]);

it('gives a game’s sign-ups the rows give: of a version, the anonymous visitors only, and whether each window to confirm has closed as of the moment asked', function () {
    ['game' => $game, 'versions' => $versions] = gameDaysFixture();
    $since = CarbonImmutable::parse('2026-11-01 00:00:00');
    $until = CarbonImmutable::parse('2026-11-17 12:00:01');

    $all = readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until);
    // The cutoff at an unconfirmed sign-up's very moment, a second before it, and part way through a day.
    $atMoment = readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until, unconfirmedBefore: CarbonImmutable::parse('2026-11-14 08:05:00'));
    $before = readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until, unconfirmedBefore: CarbonImmutable::parse('2026-11-14 08:04:59'));
    $ofVersion = readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until, $versions[1]->id);
    $partDays = readBothWays(TrafficReportQuery::class)->gameSignups($game->id, CarbonImmutable::parse('2026-11-09 09:00:00'), CarbonImmutable::parse('2026-11-16 15:30:00'));

    // The signed-in visitor is left out: eight visits, six sign-ups.
    expect($all['visits'])->toBe(8)
        ->and($all['figures']->signedUp)->toBe(6)
        ->and($atMoment['figures']->neverConfirmed)->toBe(2)
        ->and($before['figures']->neverConfirmed)->toBe(1)
        ->and($ofVersion['visits'])->toBe(3)
        ->and($partDays['visits'])->toBe(6);
});

it('lists the countries of a game’s visits from the sums by country', function () {
    ['game' => $game] = gameDaysFixture();

    expect(readBothWays(TrafficReportQuery::class)->gameCountries($game->id))->toBe(['DE', 'FR']);
});

it('reads the sums of whole days, the visits only for the part days at the ends', function () {
    ['game' => $game] = gameDaysFixture();
    fillAnalyticsRollup();
    $query = app(TrafficReportQuery::class);
    $read = function (Closure $call): string {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        return $sql;
    };

    $whole = $read(fn () => $query->gameBreakdown($game->id, CarbonImmutable::parse('2026-11-10 00:00:00'), CarbonImmutable::parse('2026-11-16 00:00:00')));
    $parts = $read(fn () => $query->gameSignups($game->id, CarbonImmutable::parse('2026-11-10 07:00:00'), CarbonImmutable::parse('2026-11-16 08:00:00')));
    $countries = $read(fn () => $query->gameCountries($game->id));

    expect($whole)->toContain('analytics_game_days')->not->toContain('analytics_visits')
        ->and($parts)->toContain('analytics_game_days')->toContain('analytics_visits')
        ->and($countries)->toContain('analytics_game_days')->not->toContain('analytics_visits');
});

it('writes a day’s sums again when one of its visits changes', function () {
    ['game' => $game] = gameDaysFixture();
    fillAnalyticsRollup();

    TrafficVisit::query()->where('arrived_at', '2026-11-14 08:00:00')->firstOrFail()->forceFill(['account_at' => '2026-11-14 08:30:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 1500])->save();
    app(RefreshAnalyticsDayAction::class)->handle('2026-11-14');
    app()->forgetScopedInstances();

    expect(app(TrafficReportQuery::class)->gameSignups($game->id, CarbonImmutable::parse('2026-11-14 00:00:00'), CarbonImmutable::parse('2026-11-15 00:00:00'))['figures']->confirmed)->toBe(1);
});

it('writes nothing of a day when its sums cannot be written, so the next run writes the whole day again', function () {
    ['game' => $game] = gameDaysFixture();
    fillAnalyticsRollup();
    TrafficVisit::query()->where('arrived_at', '2026-11-14 08:00:00')->firstOrFail()->forceFill(['account_at' => '2026-11-14 08:30:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 1500])->save();
    $failed = false;
    DB::beforeExecuting(function (string $sql) use (&$failed): void {
        if (! $failed && str_starts_with($sql, 'delete from "analytics_game_days"') || ! $failed && str_starts_with($sql, 'delete from `analytics_game_days`')) {
            $failed = true;

            throw new RuntimeException('The sums could not be written.');
        }
    });

    expect(fn () => app(RefreshAnalyticsDayAction::class)->handle('2026-11-14'))->toThrow(RuntimeException::class);

    app(RefreshAnalyticsDayAction::class)->handle('2026-11-14');
    app()->forgetScopedInstances();

    expect(app(TrafficReportQuery::class)->gameSignups($game->id, CarbonImmutable::parse('2026-11-14 00:00:00'), CarbonImmutable::parse('2026-11-15 00:00:00'))['figures']->confirmed)->toBe(1);
});

it('keeps the sums of a day the purge’s cutoff runs through, written again from the visits left, and deletes those of a day whose visits all went', function () {
    // Now 2026-11-17 12:00: visits are kept 400 days, so the cutoff is 2025-10-13 12:00.
    $game = Game::factory()->create();
    $source = TrafficSource::factory()->create();
    $visit = fn (string $at, string $day) => TrafficVisit::factory()->create(['game_id' => $game->id, 'source_id' => $source->id, 'arrived_at' => $at, 'network_day' => $day, 'opened_at' => $at, 'spend' => '0.10']);
    // A day all of whose visits go; the cutoff's own day, one visit before it and one after; a day ahead of UTC that began before it.
    $visit('2025-10-11 10:00:00', '2025-10-11');
    $visit('2025-10-13 08:00:00', '2025-10-13');
    $visit('2025-10-13 14:00:00', '2025-10-13');
    $visit('2025-10-13 11:00:00', '2025-10-14');
    $visit('2025-10-14 10:00:00', '2025-10-14');
    fillAnalyticsRollup();

    $this->artisan('traffic:purge-expired')->assertSuccessful();

    expect(AnalyticsGameDay::query()->where('network_day', '2025-10-11')->exists())->toBeFalse()
        ->and(AnalyticsDirtyDay::query()->orderBy('day')->pluck('day')->all())->toContain('2025-10-13', '2025-10-14');

    // The run that reads the marked days writes their sums from the visits left.
    app(RefreshAnalyticsVisitsAction::class)->handle(microtime(true) + 60);
    app()->forgetScopedInstances();

    $rows = readBothWays(TrafficReportQuery::class)->gameBreakdown($game->id, CarbonImmutable::parse('2025-10-01 00:00:00'), CarbonImmutable::parse('2025-10-20 00:00:00'));

    expect(collect($rows)->sum(fn (array $row): int => $row['figures']->visits))->toBe(2)
        ->and($rows[0]['first_at']->toDateTimeString())->toBe('2025-10-13 14:00:00');
});

it('reads the rows, never the sums, once the setting turns them off or before they are filled', function () {
    gameDaysFixture();
    fillAnalyticsRollup();

    config(['analytics.rollup.game_days' => false]);
    app()->forgetScopedInstances();

    expect(app(AnalyticsRollupGate::class)->readsGameDays())->toBeFalse();

    config(['analytics.rollup.game_days' => true]);
    AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->update(['game_days_filled_at' => null]);
    app()->forgetScopedInstances();

    expect(app(AnalyticsRollupGate::class)->readsGameDays())->toBeFalse();
});
