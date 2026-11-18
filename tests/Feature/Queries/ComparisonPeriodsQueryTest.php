<?php

use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Queries\Games\ComparisonPeriodsQuery;

/**
 * The traffic periods of Growth → Experiments: periods of the games not
 * archived, both audiences, that gave their visitors to a version and were
 * not hidden — the latest started first across every game, in an order that
 * keeps each in its place across pages — searched literally over the game's
 * title, internal name and slug, narrowed to comparisons and to current or
 * ended periods. Also the open comparisons the navigation counts, and the
 * periods a period's page switches between. The same on every database
 * engine.
 */
function comparisonPeriodsPage(string $search = '', string $show = 'all', string $status = 'all', int $page = 1): array
{
    return app(ComparisonPeriodsQuery::class)->paginate($search, $show, $status, $page)->pluck('id')->all();
}

it('lists the periods of every game not archived, both audiences, the latest started first and the newest first among those started together', function () {
    $taste = Game::factory()->status(GameStatus::Live)->create();
    $swipe = Game::factory()->status(GameStatus::Paused)->create();
    $oldest = comparisonPeriod($taste, [1 => 100], '2026-09-12 10:00:00', '2026-09-24 10:00:00');
    $players = comparisonPeriod($taste, [1 => 100], '2026-09-12 10:00:00', audience: GameAudience::Authenticated);
    $latest = comparisonPeriod($taste, [1 => 50, 2 => 50], '2026-10-08 10:00:00');
    $other = comparisonPeriod($swipe, [1 => 100], '2026-09-24 10:00:00');
    // Of an archived game, without shares, and hidden: never listed.
    comparisonPeriod(Game::factory()->status(GameStatus::Archived)->create(), [1 => 100], '2026-10-01 10:00:00');
    comparisonPeriod($swipe, [], '2026-10-02 10:00:00');
    comparisonPeriod($taste, [1 => 100], '2026-09-01 10:00:00', '2026-09-12 10:00:00')->forceFill(['hidden_at' => now()])->save();

    expect(comparisonPeriodsPage())->toBe([$latest->id, $other->id, $players->id, $oldest->id])
        ->and(app(ComparisonPeriodsQuery::class)->paginate('', 'all', 'all', 1)->first()?->relationLoaded('game'))->toBeTrue();
});

it('narrows to comparisons, and to current or ended periods', function () {
    $game = Game::factory()->status(GameStatus::Live)->create();
    $endedSingle = comparisonPeriod($game, [1 => 100], '2026-09-12 10:00:00', '2026-09-24 10:00:00');
    $endedPair = comparisonPeriod($game, [1 => 50, 2 => 50], '2026-09-24 10:00:00', '2026-10-08 10:00:00');
    $currentPair = comparisonPeriod($game, [1 => 80, 2 => 20], '2026-10-08 10:00:00');
    $currentTrio = comparisonPeriod($game, [1 => 50, 2 => 30, 3 => 20], '2026-10-08 10:00:00', audience: GameAudience::Authenticated);

    expect(comparisonPeriodsPage(show: 'comparisons'))->toBe([$currentTrio->id, $currentPair->id, $endedPair->id])
        ->and(comparisonPeriodsPage(status: 'current'))->toBe([$currentTrio->id, $currentPair->id])
        ->and(comparisonPeriodsPage(status: 'ended'))->toBe([$endedPair->id, $endedSingle->id])
        ->and(comparisonPeriodsPage(show: 'comparisons', status: 'ended'))->toBe([$endedPair->id]);
});

it('searches the game’s title, internal name and slug literally', function () {
    $taste = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'internal_name' => 'Taste test', 'slug' => 'trust-your-taste']);
    $blitz = Game::factory()->status(GameStatus::Live)->create(['title' => '100% Blitz', 'internal_name' => 'blitz_q4', 'slug' => 'sixty-second-blitz']);
    $tastePeriod = comparisonPeriod($taste, [1 => 100], '2026-09-12 10:00:00');
    $blitzPeriod = comparisonPeriod($blitz, [1 => 100], '2026-09-10 10:00:00');

    expect(comparisonPeriodsPage('TRUST'))->toBe([$tastePeriod->id])
        ->and(comparisonPeriodsPage('taste test'))->toBe([$tastePeriod->id])
        ->and(comparisonPeriodsPage('sixty-second'))->toBe([$blitzPeriod->id])
        // % and _ are what they say, not wildcards.
        ->and(comparisonPeriodsPage('100%'))->toBe([$blitzPeriod->id])
        ->and(comparisonPeriodsPage('%'))->toBe([$blitzPeriod->id])
        ->and(comparisonPeriodsPage('l_t'))->toBe([])
        ->and(comparisonPeriodsPage('_q4'))->toBe([$blitzPeriod->id])
        ->and(comparisonPeriodsPage('   '))->toBe([$tastePeriod->id, $blitzPeriod->id]);
});

it('reads fifty periods a page, each in one place only', function () {
    $game = Game::factory()->status(GameStatus::Live)->create();
    $version = comparisonPeriod($game, [1 => 100], '2026-01-01 00:00:00')->versions->first();

    // 51 more, every one started at the same moment: only the id orders them.
    foreach (range(1, 51) as $ignored) {
        GameTrafficPeriod::factory()->create(['game_id' => $game->id, 'started_at' => '2026-06-01 00:00:00', 'ended_at' => '2026-06-02 00:00:00'])->versions()->attach($version->id, ['share_percent' => 100]);
    }

    $first = comparisonPeriodsPage();
    $second = comparisonPeriodsPage(page: 2);

    expect($first)->toHaveCount(50)
        ->and($second)->toHaveCount(2)
        ->and(array_intersect($first, $second))->toBe([])
        ->and($first[0])->toBeGreaterThan($first[49])
        ->and(app(ComparisonPeriodsQuery::class)->paginate('', 'all', 'all', 1)->total())->toBe(52);
});

it('counts the comparisons running now: open, not hidden, of games not archived, with two versions or more', function () {
    $live = Game::factory()->status(GameStatus::Live)->create();
    $paused = Game::factory()->status(GameStatus::Paused)->create();
    comparisonPeriod($live, [1 => 50, 2 => 50], '2026-10-08 10:00:00');
    comparisonPeriod($live, [1 => 50, 2 => 30, 3 => 20], '2026-10-08 10:00:00', audience: GameAudience::Authenticated);
    comparisonPeriod($paused, [1 => 80, 2 => 20], '2026-10-01 10:00:00');
    // An ended comparison, one version alone, an archived game's comparison, a hidden one.
    comparisonPeriod($paused, [1 => 50, 2 => 50], '2026-09-01 10:00:00', '2026-10-01 10:00:00', GameAudience::Authenticated);
    comparisonPeriod(Game::factory()->status(GameStatus::Live)->create(), [1 => 100], '2026-10-01 10:00:00');
    comparisonPeriod(Game::factory()->status(GameStatus::Archived)->create(), [1 => 50, 2 => 50], '2026-10-01 10:00:00');
    comparisonPeriod(Game::factory()->status(GameStatus::Live)->create(), [1 => 50, 2 => 50], '2026-10-01 10:00:00')->forceFill(['hidden_at' => now()])->save();

    expect(app(ComparisonPeriodsQuery::class)->openComparisons())->toBe(3);
});

it('finds a period with shares, hidden or not, and offers the periods of its game and audience to switch to', function () {
    $game = Game::factory()->status(GameStatus::Archived)->create();
    $first = comparisonPeriod($game, [1 => 100], '2026-09-12 10:00:00', '2026-09-24 10:00:00');
    $hidden = comparisonPeriod($game, [1 => 50, 2 => 50], '2026-09-24 10:00:00', '2026-10-08 10:00:00');
    $hidden->forceFill(['hidden_at' => now()])->save();
    $current = comparisonPeriod($game, [1 => 80, 2 => 20], '2026-10-08 10:00:00');
    comparisonPeriod($game, [1 => 100], '2026-09-12 10:00:00', audience: GameAudience::Authenticated);
    $empty = comparisonPeriod($game, [], '2026-10-09 10:00:00');
    $query = app(ComparisonPeriodsQuery::class);

    expect($query->find($hidden->id)?->id)->toBe($hidden->id)
        // An archived game's period still opens by its address.
        ->and($query->find($current->id)?->versions->pluck('number')->all())->toBe([1, 2])
        ->and($query->find($empty->id))->toBeNull()
        ->and($query->find(999_999))->toBeNull()
        ->and($query->siblings($hidden)->pluck('id')->all())->toBe([$current->id, $first->id]);
});
