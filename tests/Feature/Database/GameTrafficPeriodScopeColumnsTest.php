<?php

use App\Enums\GameAudience;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A traffic period names whom it is for — everyone, a traffic source, or one
 * of its campaigns — and, while it is open, its scope in `open_scope`, which
 * a unique index holds to one open period per game, audience and scope on
 * every engine. The migration names each game's and audience's latest open
 * period everyone's, comes off again with its down(), and leaves the table's
 * own check on its audience in place.
 */
function scopeColumnsMigration(): object
{
    return require database_path('migrations/2026_11_18_100800_add_scope_to_game_traffic_periods_table.php');
}

/** A period row as the table takes it, of the game given. */
function scopedPeriodRow(Game $game, array $attributes = []): array
{
    return [
        'game_id' => $game->id,
        'audience' => GameAudience::Anonymous->value,
        'started_at' => now(),
        'started_by_user_id' => User::factory()->admin()->create()->id,
        ...$attributes,
    ];
}

it('adds nullable scope columns, names the latest open period of each game and audience everyone’s, and takes them off again', function () {
    $game = Game::factory()->create();
    $older = GameTrafficPeriod::factory()->create(['game_id' => $game->id]);
    $latest = GameTrafficPeriod::factory()->create(['game_id' => $game->id]);
    $ended = GameTrafficPeriod::factory()->create(['game_id' => $game->id, 'ended_at' => now()]);
    $players = GameTrafficPeriod::factory()->audience(GameAudience::Authenticated)->create(['game_id' => $game->id]);

    scopeColumnsMigration()->down();
    expect(DB::getSchemaBuilder()->hasColumns('game_traffic_periods', ['source_id', 'campaign_id', 'open_scope']))->toBeFalse();

    scopeColumnsMigration()->up();

    expect(GameTrafficPeriod::query()->orderBy('id')->pluck('open_scope', 'id')->all())->toBe([
        $older->id => null,
        $latest->id => 'everyone',
        $ended->id => null,
        $players->id => 'everyone',
    ])->and($latest->fresh())->source_id->toBeNull()->campaign_id->toBeNull();
});

it('allows one open period per game, audience and scope, and any number of ended ones', function () {
    $game = Game::factory()->create();
    $source = TrafficSource::factory()->create();
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id]);
    $insert = fn (array $attributes) => DB::table('game_traffic_periods')->insert(scopedPeriodRow($game, $attributes));

    $insert(['open_scope' => 'everyone']);
    $insert(['source_id' => $source->id, 'open_scope' => 'source:'.$source->id]);
    $insert(['source_id' => $source->id, 'campaign_id' => $campaign->id, 'open_scope' => 'campaign:'.$campaign->id]);
    $insert(['audience' => GameAudience::Authenticated->value, 'open_scope' => 'everyone']);
    // Ended periods give the name up.
    $insert(['source_id' => $source->id, 'ended_at' => now(), 'open_scope' => null]);
    $insert(['source_id' => $source->id, 'ended_at' => now(), 'open_scope' => null]);

    // Each in a savepoint: on PostgreSQL a refused insert would otherwise abort the test's transaction.
    foreach (['everyone', 'source:'.$source->id, 'campaign:'.$campaign->id] as $scope) {
        expect(fn () => DB::transaction(fn () => $insert(['open_scope' => $scope])))->toThrow(QueryException::class);
    }

    expect(GameTrafficPeriod::query()->count())->toBe(6);
});

it('keeps a source or campaign a period names from being deleted', function (string $table) {
    $source = TrafficSource::factory()->create();
    // A source's period names no campaign: the campaign's own reference would keep the source too.
    $campaign = $table === 'traffic_campaigns' ? TrafficCampaign::factory()->create(['source_id' => $source->id]) : null;
    DB::table('game_traffic_periods')->insert(scopedPeriodRow(Game::factory()->create(), ['source_id' => $source->id, 'campaign_id' => $campaign?->id]));
    $unnamed = $table === 'traffic_sources' ? TrafficSource::factory()->create() : TrafficCampaign::factory()->create(['source_id' => $source->id]);

    expect(DB::table($table)->where('id', $unnamed->id)->delete())->toBe(1)
        ->and(fn () => DB::table($table)->where('id', $campaign?->id ?? $source->id)->delete())->toThrow(QueryException::class);
})->with(['traffic_sources', 'traffic_campaigns']);

it('still refuses an audience the table doesn’t know', function () {
    $insert = fn (string $audience) => DB::table('game_traffic_periods')->insert(scopedPeriodRow(Game::factory()->create(), ['audience' => $audience, 'open_scope' => 'everyone']));

    $insert('anonymous');

    expect(fn () => $insert('everyone'))->toThrow(QueryException::class);
});

it('keeps the check SQLite would lose if it rebuilt the table', function () {
    expect((string) DB::table('sqlite_master')->where('name', 'game_traffic_periods')->value('sql'))
        ->toContain('"audience" in')
        ->toContain('"source_id" integer references "traffic_sources"')
        ->toContain('"open_scope" varchar');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'SQLite is the engine that rebuilds a table and loses its checks.');
