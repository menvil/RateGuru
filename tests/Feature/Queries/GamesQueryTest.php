<?php

use App\Enums\GameMechanic;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Queries\Games\GamesQuery;

/**
 * The games library: games not archived, the most recently saved first or by
 * name, narrowed by status and mechanic, searched literally over the title,
 * the internal name and the slug, in an order that keeps every game in its
 * place across pages.
 */
function gamesPage(string $search = '', ?GameStatus $status = null, string $sort = 'updated', int $page = 1): array
{
    return app(GamesQuery::class)->paginate($search, $status, null, $sort, $page)->pluck('id')->all();
}

it('lists the games not archived, the most recently saved first and the newest first among those saved together', function () {
    $older = Game::factory()->create(['updated_at' => now()->subDays(2)]);
    $first = Game::factory()->create(['updated_at' => now()->subHour()]);
    $second = Game::factory()->create(['updated_at' => $first->updated_at]);
    Game::factory()->status(GameStatus::Archived)->create();

    expect(gamesPage())->toBe([$second->id, $first->id, $older->id]);
});

it('lists archived games only when asked for by their status', function () {
    Game::factory()->create();
    $archived = Game::factory()->status(GameStatus::Archived)->create();
    $live = Game::factory()->status(GameStatus::Live)->create();

    expect(gamesPage(status: GameStatus::Archived))->toBe([$archived->id])
        ->and(gamesPage(status: GameStatus::Live))->toBe([$live->id])
        ->and(app(GamesQuery::class)->paginate('', null, GameMechanic::Versus, 'updated', 1)->total())->toBe(2);
});

it('sorts by name, keeping games of the same name in their place', function () {
    $b = Game::factory()->create(['title' => 'Beta']);
    $a1 = Game::factory()->create(['title' => 'Alpha']);
    $a2 = Game::factory()->create(['title' => 'Alpha']);

    expect(gamesPage(sort: 'name'))->toBe([$a2->id, $a1->id, $b->id]);
});

it('searches the title, the internal name and the slug, literally and in any case', function (string $search) {
    Game::factory()->create(['title' => 'Something else', 'internal_name' => 'Other', 'slug' => 'other']);
    $game = Game::factory()->create(['title' => 'Trust Your Taste', 'internal_name' => 'TYT 50% off_q4', 'slug' => 'trust-your-taste']);

    expect(gamesPage($search))->toBe([$game->id]);
})->with(['your TASTE', '50%', 'off_q4', 'trust-your']);

it('pages through games saved at one moment without moving or repeating any', function () {
    $moment = now()->subMinute();
    $games = Game::factory()->count(GamesQuery::PER_PAGE + 5)->create(['updated_at' => $moment]);

    $first = gamesPage();
    $second = gamesPage(page: 2);

    expect(array_intersect($first, $second))->toBe([])
        ->and(count($first) + count($second))->toBe($games->count())
        ->and([...$first, ...$second])->toBe($games->sortByDesc('id')->pluck('id')->values()->all());
});

it('counts what the header shows', function () {
    Game::factory()->create();
    Game::factory()->status(GameStatus::Live)->create();
    Game::factory()->status(GameStatus::Paused)->create();
    Game::factory()->status(GameStatus::Archived)->create();

    expect(app(GamesQuery::class)->stats())->toBe(['total' => 3, 'live' => 1, 'drafts' => 1, 'paused' => 1]);
});
