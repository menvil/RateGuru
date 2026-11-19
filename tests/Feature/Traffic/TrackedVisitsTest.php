<?php

use App\Actions\Traffic\WorkOutChancesAction;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficDimension;
use App\Enums\TrafficKnownReason;
use App\Filament\Pages\TrafficSources\TrafficSourcesView;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Queries\Traffic\TrafficChanceSampleQuery;
use App\Queries\Traffic\TrafficLogsQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * Visits to the rest of the site — the feed, posts, profiles — are
 * Analytics', not the tracker's: Traffic sources, a game's Analytics, the
 * chances the worth is worked out from and the logs read the same figures
 * with them as without them, however much those visits reached or are said
 * to be worth.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    ProjectSettings::factory()->create();
    recordTraffic(site: true);
    $this->draft = publishableGameDraft(1);
    $this->version = liveGame($this->draft);
    $this->game = $this->draft['game'];
    $this->actingAs($this->draft['admin']);
    $this->source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    $this->browsers = array_map(fn (string $name): string => hash('sha256', 'tracked-'.$name), ['a', 'b', 'c', 'd', 'e']);
    [$a, $b, $c, $d, $e] = $this->browsers;
    $onGame = ['game_id' => $this->game->id, 'version_id' => $this->version->id];
    $days = fn (int $ago): array => ['arrived_at' => now()->subDays($ago), 'network_day' => now()->subDays($ago)->toDateString(), 'last_seen_at' => now()->subDays($ago), 'opened_at' => now()->subDays($ago)];

    // A phone that clicked an ad, played, signed up and confirmed; a desktop whose sign-up still waits.
    $played = TrafficVisit::factory()->create([...$onGame, ...$days(3), 'visitor_hash' => $a, 'source_id' => $this->source->id, 'campaign_id' => $campaign->id, 'site_ref' => 's1', 'zone_ref' => 'z1', 'country' => 'US', 'spend' => '0.01',
        'started_at' => now()->subDays(3), 'completed_at' => now()->subDays(3), 'signup_at' => now()->subDays(3), 'account_at' => now()->subDays(3), 'signup_place' => 'game', 'signup_after_seconds' => 300, 'account_after_seconds' => 600, 'value_real' => '0.5']);
    TrafficVisit::factory()->create([...$onGame, ...$days(1), 'visitor_hash' => $b, 'source_id' => $this->source->id, 'site_ref' => 's2', 'zone_ref' => 'z2', 'country' => 'DE', 'device' => TrafficDevice::Desktop, 'spend' => '0.02', 'signup_at' => now()->subDay()]);
    // Unpaid visits to the game, one from a search that matured with an account, one a known visitor's that signed in.
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create([...$onGame, ...$days(20), 'visitor_hash' => $c, 'country' => 'FR', 'started_at' => now()->subDays(20), 'signup_at' => now()->subDays(20), 'account_at' => now()->subDays(20), 'account_after_seconds' => 900, 'value_real' => '0.5']);
    TrafficVisit::factory()->unpaid(TrafficChannel::Internal)->create([...$onGame, ...$days(2), 'visitor_hash' => $d, 'known' => true, 'known_reason' => TrafficKnownReason::Login, 'login_at' => now()->subDays(2)]);
    // A paid click whose window has closed, matured.
    TrafficVisit::factory()->create([...$onGame, ...$days(20), 'visitor_hash' => $e, 'source_id' => $this->source->id, 'holds_until' => now()->subDays(13), 'country' => 'US', 'started_at' => now()->subDays(20)]);

    foreach ([$a, $b, $c, $e] as $guest) {
        (new GameEvent)->forceFill(['game_id' => $this->game->id, 'version_id' => $this->version->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => $guest, 'type' => GameEventType::View, 'occurred_at' => now()->subDays(1)])->save();
    }

    GameAttempt::factory()->create(['version_id' => $this->version->id, 'visitor_hash' => $a, 'traffic_visit_id' => $played->id, 'started_at' => now()->subDays(1)]);
});

/** Everything Traffic sources draws on a tab, read as its page reads it once it is in a browser. */
function trafficSourcesTab(string $tab, array $query = []): array
{
    $page = Livewire::withQueryParams(['tab' => $tab, ...$query])->test(TrafficSourcesPage::class)->call('load')->instance();

    return app(TrafficSourcesView::class)->data($page, $page->filter());
}

/** What a game's Analytics shows, of every device and of phones alone. */
function gameAnalyticsFigures(Game $game): array
{
    $page = Livewire::test(GameAnalytics::class, ['record' => $game->id]);
    $read = fn (): array => array_map(fn (string $key): mixed => $page->viewData($key), ['kpis', 'funnel', 'signups', 'sources', 'paid', 'countryOptions']);

    return ['all' => $read(), 'mobile' => $page->set('device', 'mobile') ? $read() : []];
}

/** Every figure the tracker reads from the visits, the chances worked out from them first. */
function trackedFigures(Game $game): array
{
    app(WorkOutChancesAction::class)->handle('nightly', CarbonImmutable::now());
    $reports = app(TrafficReportQuery::class);
    $filter = new TrafficReportFilter('2026-10-19', '2026-11-17');
    $cutoff = UnconfirmedAccountsQuery::cutoff();

    return [
        'summary' => $reports->summary($filter, $cutoff),
        'levels' => [
            $reports->level($filter, TrafficDimension::Source),
            $reports->level($filter, TrafficDimension::Country),
            $reports->level($filter->within(TrafficDimension::Source, ['source_id' => null, 'channel' => 'search']), TrafficDimension::Device),
        ],
        'signups' => array_map(fn (TrafficDimension $dimension): array => $reports->signups($filter, $dimension, $cutoff), [TrafficDimension::Source, TrafficDimension::Country, TrafficDimension::Device]),
        'zones' => [$reports->zones($filter, false), $reports->zones($filter, true)],
        'groups' => $reports->byCountryGroup($filter),
        'campaigns' => $reports->byCampaign($filter),
        'game' => [
            $reports->gameBreakdown((int) $game->id, now()->subDays(30)->toImmutable()),
            $reports->gameSignups((int) $game->id, now()->subDays(30)->toImmutable(), now()->addDay()->toImmutable()),
            $reports->gameCountries((int) $game->id),
        ],
        'sample' => app(TrafficChanceSampleQuery::class)->rows(CarbonImmutable::now()),
        'clicks' => app(TrafficLogsQuery::class)->clicks(null, null, 1)->getCollection()->pluck('id')->all(),
        'tabs' => [
            'report' => trafficSourcesTab('report'),
            'sites' => trafficSourcesTab('report', ['view' => 'sites']),
            'value' => trafficSourcesTab('value'),
            'sources' => trafficSourcesTab('sources'),
            // Its sample links carry a campaign ID made up afresh on every drawing.
            'campaigns' => array_diff_key(trafficSourcesTab('campaigns'), ['samples' => true]),
        ],
        'analytics' => gameAnalyticsFigures($game),
    ];
}

it('reads Traffic sources, a game’s Analytics, the chances and the logs the same before and after visits to the rest of the site', function () {
    $before = trackedFigures($this->game);
    [$a, $b, $c, $d, $e] = $this->browsers;
    $page = fn (string $hash, int $ago, array $state = []): TrafficVisit => TrafficVisit::factory()->unpaid($state['channel'] ?? TrafficChannel::Direct)->create([
        'visitor_hash' => $hash, 'arrived_at' => now()->subDays($ago), 'network_day' => now()->subDays($ago)->toDateString(), 'last_seen_at' => now()->subDays($ago), 'opened_at' => now()->subDays($ago),
        'value_real' => '0.5', 'value_expected' => '0.2', ...$state,
    ]);

    // The same browsers on the feed and on posts, reaching everything a visit can — and said to be worth something besides.
    $page($a, 3, ['channel' => TrafficChannel::Referral, 'country' => 'US', 'signup_form_at' => now()->subDays(3), 'signup_at' => now()->subDays(3), 'account_at' => now()->subDays(3), 'signup_place' => 'feed', 'signup_after_seconds' => 60, 'account_after_seconds' => 120, 'post_published_at' => now()->subDays(2), 'returned_at' => now()->subDay()]);
    $page($a, 1, ['channel' => TrafficChannel::Internal, 'country' => 'US']);
    $page($b, 1, ['channel' => TrafficChannel::Search, 'country' => 'DE', 'device' => TrafficDevice::Desktop, 'signup_at' => now()->subDay(), 'signup_place' => 'post']);
    $page($c, 20, ['channel' => TrafficChannel::Search, 'country' => 'FR', 'signup_at' => now()->subDays(20), 'account_at' => now()->subDays(20), 'account_after_seconds' => 300]);
    $page($d, 2, ['known' => true, 'known_reason' => TrafficKnownReason::Login, 'login_at' => now()->subDays(2)]);
    $page($e, 20, ['channel' => TrafficChannel::Shared, 'country' => 'US', 'started_at' => now()->subDays(20)]);
    $page(hash('sha256', 'tracked-newcomer'), 5, ['channel' => TrafficChannel::Referral, 'country' => 'GB']);

    expect(TrafficVisit::query()->count())->toBe(12)
        ->and(trackedFigures($this->game))->toEqual($before);
});
