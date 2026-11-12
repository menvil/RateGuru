<?php

use App\Models\GameRound;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\MediaAsset;
use App\Models\Post;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The rules the game tables keep themselves, alike on PostgreSQL, SQLite and
 * MariaDB, although SQLite gets the three tables with a CHECK from statements
 * of their own (docs/architecture/games.md, "Database checks"). Rows go in
 * through the query builder, past every action and model; a refused one in a
 * savepoint of its own, as PostgreSQL aborts a transaction on a refused
 * statement.
 */

beforeEach(function () {
    $this->round = GameRound::factory()->create();
    $this->post = Post::factory()->create();
    $this->asset = MediaAsset::factory()->postImage()->create();
});

/**
 * A round option as it would be written straight into the table: in slot
 * `$position` of the test's round, showing nothing unless the fields say so.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function rawGameRoundOption(int $position, array $fields): array
{
    return [
        'round_id' => test()->round->id,
        'position' => $position,
        'source' => 'post',
        'post_id' => null,
        'asset_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$fields,
    ];
}

it('keeps an uploaded picture off a post option', function () {
    DB::table('game_round_options')->insert([
        rawGameRoundOption(0, ['source' => 'post', 'post_id' => $this->post->id]),
        rawGameRoundOption(1, ['source' => 'upload', 'asset_id' => $this->asset->id]),
        // An upload that still names a post is SaveContentSetAction's to
        // refuse: no engine checks it, as MariaDB takes no CHECK on a column
        // the post purge sets to null.
        rawGameRoundOption(2, ['source' => 'upload', 'post_id' => $this->post->id, 'asset_id' => $this->asset->id]),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('game_round_options')->insert(
        rawGameRoundOption(3, ['source' => 'post', 'post_id' => $this->post->id, 'asset_id' => $this->asset->id]),
    )))->toThrow(QueryException::class);

    expect(DB::table('game_round_options')->count())->toBe(3);
});

it('takes a round\'s options with it when the round is deleted', function () {
    $other = GameRound::factory()->create();
    DB::table('game_round_options')->insert([
        rawGameRoundOption(0, ['post_id' => $this->post->id]),
        rawGameRoundOption(1, ['source' => 'upload', 'asset_id' => $this->asset->id]),
        rawGameRoundOption(0, ['round_id' => $other->id, 'post_id' => $this->post->id]),
    ]);

    DB::table('game_rounds')->where('id', $this->round->id)->delete();

    expect(DB::table('game_round_options')->pluck('round_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$other->id]);
});

it('lets the post purge detach a post from the options showing it', function () {
    DB::table('game_round_options')->insert(rawGameRoundOption(0, ['post_id' => $this->post->id]));

    DB::table('posts')->where('id', $this->post->id)->delete();

    expect(DB::table('game_round_options')->where('round_id', $this->round->id)->sole()->post_id)->toBeNull();
});

it('refuses to delete a media asset an option still shows', function () {
    DB::table('game_round_options')->insert(rawGameRoundOption(0, ['source' => 'upload', 'asset_id' => $this->asset->id]));

    expect(fn () => DB::transaction(fn () => DB::table('media_assets')->where('id', $this->asset->id)->delete()))
        ->toThrow(QueryException::class);

    expect(DB::table('media_assets')->where('id', $this->asset->id)->exists())->toBeTrue()
        ->and((int) DB::table('game_round_options')->where('round_id', $this->round->id)->value('asset_id'))->toBe($this->asset->id);
});

it('takes a traffic share of 1 to 100 percent and no other', function (int $percent, bool $taken) {
    $version = GameVersion::factory()->create();
    $period = GameTrafficPeriod::factory()->create(['game_id' => $version->game_id]);
    $insert = fn () => DB::table('game_traffic_shares')->insert(['period_id' => $period->id, 'version_id' => $version->id, 'share_percent' => $percent]);

    if ($taken) {
        $insert();
    } else {
        expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
    }

    expect(DB::table('game_traffic_shares')->where('period_id', $period->id)->exists())->toBe($taken);
})->with([
    'none' => [0, false],
    'the least' => [1, true],
    'all of it' => [100, true],
    'more than all' => [101, false],
]);

it('assigns a version to a signed-in player or an anonymous browser, exactly one of them', function (bool $user, bool $visitor, bool $taken) {
    $version = GameVersion::factory()->create();
    $period = GameTrafficPeriod::factory()->create(['game_id' => $version->game_id]);
    $insert = fn () => DB::table('game_assignments')->insert([
        'game_id' => $version->game_id,
        'audience' => 'anonymous',
        'user_id' => $user ? $version->published_by_user_id : null,
        'visitor_hash' => $visitor ? str_repeat('a', 64) : null,
        'version_id' => $version->id,
        'period_id' => $period->id,
        'assigned_at' => now(),
        'last_seen_at' => now(),
    ]);

    if ($taken) {
        $insert();
    } else {
        expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
    }

    expect(DB::table('game_assignments')->where('period_id', $period->id)->exists())->toBe($taken);
})->with([
    'a signed-in player' => [true, false, true],
    'an anonymous browser' => [false, true, true],
    'both' => [true, true, false],
    'neither' => [false, false, false],
]);
