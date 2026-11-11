<?php

use App\Actions\Games\CreateGameAction;
use App\Actions\Games\SaveGameGeneralAction;
use App\Data\Games\GameGeneralData;
use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameFlowStep;
use App\Models\User;
use Illuminate\Validation\ValidationException;

function newGameData(array $overrides = []): GameGeneralData
{
    return new GameGeneralData(...array_merge([
        'title' => 'Trust Your Taste',
        'internalName' => '',
        'slug' => 'trust-your-taste',
        'description' => 'Pick the photo you like more.',
    ], $overrides));
}

it('creates a draft game with an empty content set and a Result First flow', function () {
    $admin = User::factory()->admin()->create();

    $game = app(CreateGameAction::class)->handle($admin, newGameData());

    expect($game->status)->toBe(GameStatus::Draft)
        ->and($game->internal_name)->toBe('Trust Your Taste')
        ->and($game->created_by_user_id)->toBe($admin->id)
        ->and($game->contentSets()->sole()->name)->toBe('Standard')
        ->and($game->contentSets()->sole()->crowd_min_votes)->toBe(100)
        ->and($game->contentSets()->sole()->crowd_min_gap)->toBe(0)
        ->and($game->flows()->sole()->name)->toBe('Result First')
        ->and($game->flows()->sole()->steps->map(fn (GameFlowStep $step) => $step->type)->all())
        ->toBe([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult])
        ->and($game->flows()->sole()->steps->first()->title)->toBe('Trust Your Taste');
});

it('holds a new game to the general rules', function (array $overrides, string $field) {
    Game::factory()->create(['slug' => 'taken']);

    expect(fn () => app(CreateGameAction::class)->handle(User::factory()->admin()->create(), newGameData($overrides)))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));
})->with([
    'no title' => [['title' => ' '], 'title'],
    'a title too long' => [['title' => str_repeat('a', 121)], 'title'],
    'no slug' => [['slug' => ''], 'slug'],
    'a slug with spaces' => [['slug' => 'trust your taste'], 'slug'],
    'a slug with double hyphens' => [['slug' => 'trust--taste'], 'slug'],
    'a slug in use' => [['slug' => 'taken'], 'slug'],
    'a long description' => [['description' => str_repeat('a', 241)], 'description'],
]);

it('lets only an administrator who may manage games create one', function (string $role) {
    app(CreateGameAction::class)->handle(User::factory()->{$role}()->create(), newGameData());
})->with(['moderator', 'persona'])->throws(CannotManageGameException::class);

it('saves the general fields at the version it was drawn from, and refuses a stale save', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    app(SaveGameGeneralAction::class)->handle($admin, $game, newGameData(['slug' => 'renamed']), $game->lock_version);

    expect($game->fresh()->slug)->toBe('renamed')
        ->and($game->fresh()->lock_version)->toBe(2)
        ->and($game->fresh()->updated_by_user_id)->toBe($admin->id);

    expect(fn () => app(SaveGameGeneralAction::class)->handle($admin, $game, newGameData(['title' => 'Again']), 1))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());
});

it('locks the slug once a version has been published, but not the title', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->published()->create(['slug' => 'locked-slug']);

    app(SaveGameGeneralAction::class)->handle($admin, $game, newGameData(['slug' => 'locked-slug', 'title' => 'New title']), $game->lock_version);

    expect($game->fresh()->title)->toBe('New title');

    expect(fn () => app(SaveGameGeneralAction::class)->handle($admin, $game->fresh(), newGameData(['slug' => 'another']), $game->fresh()->lock_version))
        ->toThrow(CannotManageGameException::class, 'The slug and the mechanic are locked once a version has been published.');
});
