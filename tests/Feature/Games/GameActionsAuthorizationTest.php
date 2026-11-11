<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeContentSetAction;
use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\ChangeGameStatusAction;
use App\Actions\Games\ChangeGameVersionAction;
use App\Actions\Games\CreateContentSetAction;
use App\Actions\Games\CreateGameAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\SaveContentSetAction;
use App\Actions\Games\SaveGameFlowAction;
use App\Actions\Games\SaveGameGeneralAction;
use App\Actions\Games\SetGameCoverAction;
use App\Data\Games\ContentSetDraft;
use App\Data\Games\GameFlowDraft;
use App\Data\Games\GameGeneralData;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Every administrator action of Growth → Games asks itself whether its caller
 * may manage games, whatever the page in front of it already asked: someone
 * who may not, calling one directly, is refused and nothing is written.
 */

/** @return list<int> what a game action writes to, counted and versioned */
function gameWriteState(array $draft): array
{
    return [
        Game::query()->count(),
        (int) Game::query()->whereKey($draft['game']->id)->value('lock_version'),
        GameContentSet::query()->count(),
        (int) GameContentSet::query()->whereKey($draft['set']->id)->value('lock_version'),
        GameFlow::query()->count(),
        (int) GameFlow::query()->whereKey($draft['flow']->id)->value('lock_version'),
        GameVersion::query()->count(),
        GameTrafficPeriod::query()->count(),
    ];
}

it('refuses every game action to someone who may not manage games, and writes nothing', function (Closure $action) {
    Storage::fake('public');
    $draft = publishableGameDraft(1);
    $draft['version'] = app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game'], $draft['set']->id, $draft['flow']->id);
    $draft['game'] = $draft['game']->fresh();
    $before = gameWriteState($draft);

    expect(fn () => $action(User::factory()->moderator()->create(), $draft))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isNotAllowed())->toBeTrue());

    expect(gameWriteState($draft))->toBe($before);
})->with([
    'create a game' => [fn (User $user, array $d) => app(CreateGameAction::class)->handle($user, new GameGeneralData(title: 'Another game', slug: 'another-game'))],
    'save the general fields' => [fn (User $user, array $d) => app(SaveGameGeneralAction::class)->handle($user, $d['game'], GameGeneralData::of($d['game']), $d['game']->lock_version)],
    'set the cover' => [fn (User $user, array $d) => app(SetGameCoverAction::class)->handle($user, $d['game'], UploadedFile::fake()->image('cover.jpg', 1600, 900), $d['game']->lock_version)],
    'remove the cover' => [fn (User $user, array $d) => app(SetGameCoverAction::class)->remove($user, $d['game'], $d['game']->lock_version)],
    'add a content set' => [fn (User $user, array $d) => app(CreateContentSetAction::class)->handle($user, $d['game'], 'Another set')],
    'save a content set' => [fn (User $user, array $d) => app(SaveContentSetAction::class)->handle($user, $d['set'], ContentSetDraft::of(GameContentSet::query()->with(['rounds.options', 'profiles'])->findOrFail($d['set']->id)), $d['set']->lock_version)],
    'rename a content set' => [fn (User $user, array $d) => app(ChangeContentSetAction::class)->rename($user, $d['set'], 'Renamed')],
    'archive a content set' => [fn (User $user, array $d) => app(ChangeContentSetAction::class)->archive($user, $d['set'])],
    'restore a content set' => [fn (User $user, array $d) => app(ChangeContentSetAction::class)->restore($user, $d['set'])],
    'delete a content set' => [fn (User $user, array $d) => app(ChangeContentSetAction::class)->delete($user, $d['set'])],
    'add a flow' => [fn (User $user, array $d) => app(ChangeGameFlowAction::class)->create($user, $d['game'], 'Another flow', GameFlowTemplate::DirectPlay)],
    'save a flow' => [fn (User $user, array $d) => app(SaveGameFlowAction::class)->handle($user, $d['flow'], GameFlowDraft::of($d['flow']->load('steps')), $d['flow']->lock_version)],
    'rename a flow' => [fn (User $user, array $d) => app(ChangeGameFlowAction::class)->rename($user, $d['flow'], 'Renamed')],
    'archive a flow' => [fn (User $user, array $d) => app(ChangeGameFlowAction::class)->archive($user, $d['flow'])],
    'restore a flow' => [fn (User $user, array $d) => app(ChangeGameFlowAction::class)->restore($user, $d['flow'])],
    'delete a flow' => [fn (User $user, array $d) => app(ChangeGameFlowAction::class)->delete($user, $d['flow'])],
    'publish a version' => [fn (User $user, array $d) => app(PublishGameVersionAction::class)->handle($user, $d['game'], $d['set']->id, $d['flow']->id)],
    'apply traffic' => [fn (User $user, array $d) => app(ApplyGameTrafficAction::class)->handle($user, $d['game'], GameAudience::Anonymous, [$d['version']->id => 100])],
    'archive a version' => [fn (User $user, array $d) => app(ChangeGameVersionAction::class)->retire($user, $d['version'])],
    'restore a version' => [fn (User $user, array $d) => app(ChangeGameVersionAction::class)->restore($user, $d['version'])],
    'change the status' => [fn (User $user, array $d) => app(ChangeGameStatusAction::class)->handle($user, $d['game'], GameStatus::Archived)],
]);
