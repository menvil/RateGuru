<?php

namespace Tests\Feature\Seeders;

use App\Enums\GameAudience;
use App\Models\AccountAttribution;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Games\GameMetricsQuery;
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
 * version played, attempts on the visits they came on, accounts confirmed
 * and not — and only once.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $admin = User::factory()->admin()->create(['email' => 'admin@rateguru.test']);

    foreach ([[900, 100], [850, 150], [800, 200], [750, 250], [700, 300], [400, 600], [350, 650], [300, 700], [250, 750], [200, 800]] as [$up, $down]) {
        gamePost($up, $down);
    }

    foreach (range(1, 200) as $n) {
        User::factory()->create(['email' => "fill{$n}@demo.test"]);
    }

    $this->seed(GamesDemoSeeder::class);
    // What TrafficDemoSeeder leaves that the history needs: its PopAds source, and visits recorded.
    TrafficSource::factory()->create(['name' => 'PopAds main', 'link_key' => 'pop', 'preset_key' => 'popads', 'created_by_user_id' => $admin->id]);
    (new TrafficSetting)->forceFill(['id' => 1, 'capture_enabled' => true, 'postbacks_enabled' => false])->save();
});

it('plays the demo game\'s history through, every version on traffic, once', function () {
    (new ShortGameAnalyticsDemoSeeder)->__invoke();

    $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->sole();
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
        ->and(TrafficVisit::query()->whereIn('id', $linked->pluck('traffic_visit_id'))->where('game_id', '!=', $game->id)->exists())->toBeFalse()
        ->and(AccountAttribution::query()->whereNotNull('confirmed_at')->exists())->toBeTrue()
        ->and(TrafficVisit::query()->whereNotNull('signup_at')->whereNull('account_at')->exists())->toBeTrue();

    (new ShortGameAnalyticsDemoSeeder)->__invoke();

    expect(GameAttempt::query()->where('game_id', $game->id)->count())->toBe($attempts)
        ->and(GameVersion::query()->where('game_id', $game->id)->count())->toBe(3);
});
