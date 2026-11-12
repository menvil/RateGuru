<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameRound;
use App\Models\GameVersion;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Settings\ProjectSettingsManager;
use Database\Seeders\GamesDemoSeeder;

/**
 * The demo game is built from the site's own posts the way an administrator
 * builds one — five decided rounds, published, given every visitor and made
 * live, with games switched on — and only once: a second run, or a database
 * without the posts it needs, changes nothing.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    User::factory()->admin()->create(['email' => 'admin@rateguru.test']);
});

it('builds, publishes and opens the demo game from posts the crowd has decided between', function () {
    foreach ([[900, 100], [850, 150], [800, 200], [750, 250], [700, 300], [400, 600], [350, 650], [300, 700], [250, 750], [200, 800]] as [$up, $down]) {
        gamePost($up, $down);
    }

    $this->seed(GamesDemoSeeder::class);

    $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->sole();
    $version = GameVersion::query()->where('game_id', $game->id)->sole();

    expect($game->status)->toBe(GameStatus::Live)
        ->and($version->round_count)->toBe(5)
        ->and($version->scored_round_count)->toBe(5)
        ->and(app(GameTrafficQuery::class)->hasPlayableTraffic((int) $game->id))->toBeTrue()
        ->and(app(ProjectSettingsManager::class)->featureEnabled('allow_games'))->toBeTrue()
        // The better liked post is not always on the same side.
        ->and(array_map(fn ($round): ?int => $round->answer, $version->read()->rounds))->toBe([1, 0, 1, 0, 1])
        // Asked the way the rounds are scored — by the share of upvotes, not their count.
        ->and(array_unique(array_map(fn ($round): string => $round->question->in('en'), $version->read()->rounds)))->toBe(['Which one did people like more?']);

    $this->seed(GamesDemoSeeder::class);

    expect(Game::query()->count())->toBe(1)
        ->and(GameVersion::query()->count())->toBe(1)
        ->and(GameRound::query()->count())->toBe(5);
});

it('switches games on in a database that has no settings row yet', function () {
    ProjectSettings::query()->delete();
    app(ProjectSettingsManager::class)->flush();

    foreach ([[900, 100], [850, 150], [800, 200], [750, 250], [700, 300], [400, 600], [350, 650], [300, 700], [250, 750], [200, 800]] as [$up, $down]) {
        gamePost($up, $down);
    }

    $this->seed(GamesDemoSeeder::class);

    expect(Game::query()->where('slug', GamesDemoSeeder::SLUG)->exists())->toBeTrue()
        ->and(ProjectSettings::query()->find(1))->not->toBeNull()
        ->and(app(ProjectSettingsManager::class)->featureEnabled('allow_games'))->toBeTrue();
});

it('makes no game from posts the crowd has not decided between, or that a game cannot show', function (Closure $posts) {
    // Ten posts: enough for five rounds, were it not for the one rule each set breaks.
    $posts();

    $this->seed(GamesDemoSeeder::class);

    expect(Game::query()->exists())->toBeFalse();
})->with([
    'fewer site votes than a new set asks for' => [function () {
        foreach ([[8, 1], [1, 8], [7, 1], [1, 7], [6, 2], [2, 6], [5, 1], [1, 5], [4, 1], [1, 4]] as [$up, $down]) {
            gamePost($up, $down);
        }
    }],
    'every share the same' => [function () {
        foreach (range(1, 10) as $n) {
            gamePost(500, 500);
        }
    }],
    'no picture in the feed\'s size yet' => [function () {
        foreach ([[900, 100], [100, 900], [850, 150], [150, 850], [800, 200], [200, 800], [750, 250], [250, 750], [700, 300], [300, 700]] as [$up, $down]) {
            Post::factory()->published()->withImage()->create(['upvotes_count' => $up, 'downvotes_count' => $down]);
        }
    }],
]);
