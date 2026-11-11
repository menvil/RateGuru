<?php

use App\Models\Game;
use App\Models\GameContentSet;
use Illuminate\Support\Facades\DB;

/*
 * A new content set asks of the crowd what config/games.php says — ten site
 * votes for each post and no gap between their shares — however the set is
 * made: by the model, or by a row written without them.
 */

it('asks of the crowd what config/games.php asks, however the set is made', function () {
    $default = ['crowd_min_votes' => 10, 'crowd_min_gap' => 0];
    $game = Game::factory()->create();
    DB::table('game_content_sets')->insert(['game_id' => $game->id, 'name' => 'Written', 'created_at' => now(), 'updated_at' => now()]);
    $written = GameContentSet::query()->where('name', 'Written')->sole();
    $made = new GameContentSet;

    expect(config('games.crowd'))->toBe(['min_votes' => 10, 'min_gap' => 0])
        ->and($made->only(['crowd_min_votes', 'crowd_min_gap']))->toBe($default)
        ->and($written->only(['crowd_min_votes', 'crowd_min_gap']))->toBe($default);
});
