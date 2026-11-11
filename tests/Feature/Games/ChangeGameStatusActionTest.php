<?php

use App\Actions\Games\ChangeGameStatusAction;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\GameVersionPost;
use App\Models\Post;
use App\Models\User;

/** A game with a published version given every visitor of the anonymous audience. */
function gameWithTraffic(GameStatus $status = GameStatus::Draft): Game
{
    $version = GameVersion::factory()->create();
    $version->game->forceFill(['status' => $status])->save();
    GameTrafficPeriod::factory()->create(['game_id' => $version->game_id, 'audience' => GameAudience::Anonymous])
        ->versions()->attach($version->id, ['share_percent' => 100]);

    return $version->game->fresh();
}

it('makes a game live only once a playable version has traffic', function () {
    $admin = User::factory()->admin()->create();
    $bare = Game::factory()->create();

    expect(fn () => app(ChangeGameStatusAction::class)->handle($admin, $bare, GameStatus::Live))
        ->toThrow(CannotManageGameException::class, 'Publish a version and give it a share in Traffic first.');

    $game = gameWithTraffic();
    app(ChangeGameStatusAction::class)->handle($admin, $game, GameStatus::Live);

    expect($game->fresh()->status)->toBe(GameStatus::Live)
        ->and($game->fresh()->status_changed_at)->not->toBeNull();
});

it('refuses to go live while every version with traffic shows a post that left the site', function () {
    $game = gameWithTraffic();
    $post = Post::factory()->published()->create();
    GameVersionPost::query()->create(['version_id' => $game->versions()->first()->id, 'post_id' => $post->id]);
    $post->forceFill(['status' => 'hidden'])->save();

    app(ChangeGameStatusAction::class)->handle(User::factory()->admin()->create(), $game, GameStatus::Live);
})->throws(CannotManageGameException::class, 'Give a version a share in Traffic first.');

it('pauses, resumes, archives and restores', function () {
    $admin = User::factory()->admin()->create();
    $game = gameWithTraffic(GameStatus::Live);
    $change = fn (GameStatus $to) => app(ChangeGameStatusAction::class)->handle($admin, $game->fresh(), $to);

    $change(GameStatus::Paused);
    expect($game->fresh()->status)->toBe(GameStatus::Paused);

    $change(GameStatus::Live);
    $change(GameStatus::Archived);
    expect($game->fresh()->status)->toBe(GameStatus::Archived);

    // A published game comes back paused, never as a draft.
    expect(fn () => $change(GameStatus::Draft))->toThrow(CannotManageGameException::class);
    $change(GameStatus::Paused);
    expect($game->fresh()->status)->toBe(GameStatus::Paused);
});

it('restores a game never published as a draft', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->status(GameStatus::Archived)->create();

    app(ChangeGameStatusAction::class)->handle($admin, $game, GameStatus::Draft);

    expect($game->fresh()->status)->toBe(GameStatus::Draft);
});

it('pauses only a live game', function () {
    app(ChangeGameStatusAction::class)->handle(User::factory()->admin()->create(), Game::factory()->create(), GameStatus::Paused);
})->throws(CannotManageGameException::class, 'Only a live game can be paused.');
