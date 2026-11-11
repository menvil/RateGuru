<?php

use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Enums\UserStatus;
use App\Models\Game;
use App\Models\GameFlowStep;
use App\Models\User;

/*
 * Games arrive with the release that brings them: the migration makes Trust
 * Your Taste as a draft for the site's first active administrator to fill,
 * once, and only where there is an administrator to credit it to.
 */

function trustYourTasteGameMigration(): object
{
    return require database_path('migrations/2026_11_11_120000_add_the_trust_your_taste_game.php');
}

it('makes Trust Your Taste as a draft, with an empty content set and a Result First flow', function () {
    $admin = User::factory()->admin()->create();

    trustYourTasteGameMigration()->up();

    $game = Game::query()->sole();

    expect($game->slug)->toBe('trust-your-taste')
        ->and($game->title)->toBe('Trust Your Taste')
        ->and($game->status)->toBe(GameStatus::Draft)
        ->and($game->first_published_at)->toBeNull()
        ->and($game->created_by_user_id)->toBe($admin->id)
        ->and($game->contentSets()->sole()->name)->toBe('Standard')
        ->and($game->contentSets()->sole()->rounds()->count())->toBe(0)
        ->and($game->flows()->sole()->name)->toBe('Result First')
        ->and($game->flows()->sole()->steps()->orderBy('position')->get()->map(fn (GameFlowStep $step) => $step->type)->all())
        ->toBe([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult]);
});

it('credits the first active administrator, not a banned one or a moderator', function () {
    User::factory()->moderator()->create();
    User::factory()->admin()->create(['status' => UserStatus::Banned]);
    $active = User::factory()->admin()->create();

    trustYourTasteGameMigration()->up();

    expect(Game::query()->sole()->created_by_user_id)->toBe($active->id);
});

it('makes it once, and leaves the game there is as it is', function () {
    User::factory()->admin()->create();
    trustYourTasteGameMigration()->up();
    Game::query()->sole()->forceFill(['title' => 'Edited since'])->save();

    trustYourTasteGameMigration()->up();

    expect(Game::query()->sole()->title)->toBe('Edited since');
});

it('makes nothing on a database with no administrator yet', function () {
    User::factory()->create();

    trustYourTasteGameMigration()->up();

    expect(Game::query()->exists())->toBeFalse();
});

it('takes back a game never published, and keeps one that was', function () {
    User::factory()->admin()->create();
    trustYourTasteGameMigration()->up();

    trustYourTasteGameMigration()->down();

    expect(Game::query()->exists())->toBeFalse();

    trustYourTasteGameMigration()->up();
    Game::query()->sole()->forceFill(['first_published_at' => now()])->save();

    trustYourTasteGameMigration()->down();

    expect(Game::query()->count())->toBe(1);
});
