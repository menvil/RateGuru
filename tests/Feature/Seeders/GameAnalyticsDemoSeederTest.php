<?php

namespace Tests\Feature\Seeders;

use App\Enums\GameAudience;
use App\Enums\TrafficChannel;
use App\Models\AccountAttribution;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\LandingRoute;
use App\Support\Games\Analytics\GameMetricsScope;
use Carbon\CarbonImmutable;
use Database\Seeders\GameAnalyticsDemoSeeder;
use Database\Seeders\GamesDemoSeeder;

/** The demo history over a week and a half instead of two months, so it plays in seconds. */
class ShortGameAnalyticsDemoSeeder extends GameAnalyticsDemoSeeder
{
    protected function days(): int
    {
        return 9;
    }
}

/**
 * The demo game's history, played through the game's and the tracker's own
 * actions: three versions sharing the traffic period after period, every
 * version played, attempts on the visits they came on, the visits already
 * recorded played on their own rows, accounts confirmed and not, only its
 * own unconfirmed ones removed — and only once.
 */
beforeEach(function () {
    demoGameWithMembers();
});

it('plays the demo game\'s history through, every version on traffic, once', function () {
    $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->sole();
    $first = GameVersion::query()->where('game_id', $game->id)->sole();

    // A Google search visit as TrafficDemoSeeder records one — opened, its own first, with the version then
    // shown — half a day back, after the shares last moved: played through on its own row, not a second one.
    $arrived = CarbonImmutable::now()->subHours(12)->startOfSecond();
    $recorded = TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create([
        'source_key' => AnalyticsSource::keyFor(TrafficChannel::Search, null, 'www.google.com'),
        'landing_key' => LandingRoute::key('games.play', (int) $game->id, (int) $first->id),
        'game_id' => $game->id,
        'version_id' => $first->id,
        'country' => 'US',
        'city' => 'Austin',
        'arrived_at' => $arrived,
        'network_day' => $arrived->toDateString(),
        'last_seen_at' => $arrived,
        'opened_at' => $arrived,
    ]);
    $recorded->forceFill(['entry_visit_id' => $recorded->id])->save();

    // Somebody's own account, unconfirmed past its week and named like the history's: not the history's to remove.
    $stranger = User::factory()->unverified()->create(['username' => 'player_alex', 'created_at' => now()->subDays(10)]);

    (new ShortGameAnalyticsDemoSeeder)->__invoke();

    $versions = GameVersion::query()->where('game_id', $game->id)->orderBy('number')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    $now = CarbonImmutable::now()->addSecond();
    $funnels = app(GameMetricsQuery::class)->funnels(new GameMetricsScope((int) $game->id, GameAudience::Anonymous, $now->subDays(30), $now, null), 'version');
    $attempts = GameAttempt::query()->where('game_id', $game->id)->count();

    expect($versions)->toHaveCount(3)
        ->and(GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous)->count())->toBe(4)
        ->and(TrafficSource::query()->count())->toBe(11);

    foreach ($versions as $version) {
        expect($funnels[$version]->visitors ?? 0)->toBeGreaterThan(0)
            ->and($funnels[$version]->started ?? 0)->toBeGreaterThan(0);
    }

    // Each attempt from tracked traffic is on its visit, which shows the game.
    $linked = GameAttempt::query()->where('game_id', $game->id)->whereNotNull('traffic_visit_id')->get(['traffic_visit_id']);
    expect($linked)->not->toBeEmpty()
        ->and(TrafficVisit::query()->whereIn('id', $linked->pluck('traffic_visit_id'))->where(fn ($query) => $query->whereNull('game_id')->orWhere('game_id', '!=', $game->id))->exists())->toBeFalse()
        ->and(AccountAttribution::query()->whereNotNull('confirmed_at')->exists())->toBeTrue()
        ->and(TrafficVisit::query()->whereNotNull('signup_at')->whereNull('account_at')->exists())->toBeTrue();

    // The recorded visit, played through: the same row, opened seconds after it came (left alone, it would
    // still be last seen when it arrived), given the version it showed, its landing naming that version.
    $replayed = $recorded->fresh();
    expect($replayed->last_seen_at->greaterThan($arrived))->toBeTrue()
        ->and($replayed->version_id)->not->toBeNull()
        ->and($replayed->landing_key)->toBe(LandingRoute::key('games.play', (int) $game->id, (int) $replayed->version_id))
        ->and(TrafficVisit::query()->where('visitor_hash', $recorded->visitor_hash)->whereKeyNot($recorded->id)->whereBetween('arrived_at', [$arrived->subMinute(), $arrived->addMinute()])->exists())->toBeFalse();

    // The history's own accounts unconfirmed past their week are removed, and no one else's. The cutoff
    // here is an hour back, so it is never later than the one the run used a moment ago.
    expect(app(UnconfirmedAccountsQuery::class)->expired(UnconfirmedAccountsQuery::cutoff()->subHour())->whereKeyNot($stranger->id)->exists())->toBeFalse()
        ->and($stranger->fresh()->anonymized_at)->toBeNull();

    (new ShortGameAnalyticsDemoSeeder)->__invoke();

    expect(GameAttempt::query()->where('game_id', $game->id)->count())->toBe($attempts)
        ->and(GameVersion::query()->where('game_id', $game->id)->count())->toBe(3);
});
