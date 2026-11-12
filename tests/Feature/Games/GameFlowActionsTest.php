<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\SaveGameFlowAction;
use App\Data\Games\GameFlowDraft;
use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameVersion;
use App\Support\Games\Flow\GameFlowTemplate;
use Illuminate\Validation\ValidationException;

it('saves steps added, reworded, moved and removed, each with its type\'s settings', function () {
    $draft = publishableGameDraft(1);
    $flow = $draft['flow']->load('steps');
    $steps = GameFlowDraft::of($flow)->steps;
    $steps[0]['texts']['title'] = 'Pick your favourite';
    $steps[] = GameFlowDraft::newStep(GameFlowStepType::Share, 'nshare');
    $steps[] = GameFlowDraft::newStep(GameFlowStepType::Feed, 'nfeed');
    $steps[1]['settings'] = ['advance' => 'button', 'advance_seconds' => 99, 'nonsense' => true];

    app(SaveGameFlowAction::class)->handle($draft['admin'], $flow, new GameFlowDraft($steps), $flow->lock_version);

    $saved = GameFlow::query()->find($flow->id)->steps;

    expect($saved->map(fn (GameFlowStep $step) => $step->type->value)->all())->toBe(['landing', 'game', 'full_result', 'share', 'feed'])
        ->and($saved[0]->title)->toBe('Pick your favourite')
        ->and($saved[1]->settings)->toEqual(['advance' => 'button', 'advance_seconds' => 8])
        ->and($saved[3]->body)->toBe('I got {profile} in {game}. What is your taste?')
        ->and($saved[0]->field_label)->toBeNull();
});

it('keeps an email or sign-up step for anonymous visitors whatever the draft says', function () {
    $draft = publishableGameDraft(1);
    $flow = $draft['flow']->load('steps');
    $steps = GameFlowDraft::of($flow)->steps;
    $email = GameFlowDraft::newStep(GameFlowStepType::EmailCapture, 'nemail');
    $email['audience'] = GameStepAudience::All->value;
    array_splice($steps, 2, 0, [$email]);

    app(SaveGameFlowAction::class)->handle($draft['admin'], $flow, new GameFlowDraft($steps), $flow->lock_version);

    expect(GameFlowStep::query()->where('type', 'email_capture')->sole()->audience)->toBe(GameStepAudience::Anonymous);
});

it('refuses texts too long and steps of a kind it does not know', function (Closure $break, string $key) {
    $draft = publishableGameDraft(1);
    $flow = $draft['flow']->load('steps');
    $steps = $break(GameFlowDraft::of($flow)->steps);

    expect(fn () => app(SaveGameFlowAction::class)->handle($draft['admin'], $flow, new GameFlowDraft($steps), $flow->lock_version))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($key));
})->with([
    'a long title' => [function (array $steps) {
        $steps[0]['texts']['title'] = str_repeat('a', 161);

        return $steps;
    }, 'steps.0.texts.title'],
    'an unknown kind' => [function (array $steps) {
        $steps[0]['type'] = 'quiz_magic';

        return $steps;
    }, 'steps.0.type'],
]);

it('refuses a save drawn before somebody else\'s', function () {
    $draft = publishableGameDraft(1);
    $flow = $draft['flow']->load('steps');

    app(SaveGameFlowAction::class)->handle($draft['admin'], $flow, GameFlowDraft::of($flow), $flow->lock_version + 1);
})->throws(CannotManageGameException::class, 'Someone saved this flow after you opened it. Reload to see their changes.');

it('makes a flow from a template or as a copy', function () {
    $draft = publishableGameDraft(1);

    $email = app(ChangeGameFlowAction::class)->create($draft['admin'], $draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    $copy = app(ChangeGameFlowAction::class)->create($draft['admin'], $draft['game'], 'Copy', null, $draft['flow']->id);

    expect($email->steps()->pluck('type')->map->value->all())->toBe(['landing', 'game', 'result_preview', 'email_capture', 'full_result', 'registration'])
        ->and($copy->steps()->count())->toBe(3);
});

it('renames or restores a flow at the version its editor was drawn from, and refuses either over a newer save', function () {
    $draft = publishableGameDraft(1);
    $flow = $draft['flow'];
    $admin = $draft['admin'];

    app(ChangeGameFlowAction::class)->rename($admin, $flow, 'Renamed', 1);
    $flow->forceFill(['archived_at' => now()])->save();

    expect(fn () => app(ChangeGameFlowAction::class)->rename($admin, $flow, 'Again', 1))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());
    expect(fn () => app(ChangeGameFlowAction::class)->restore($admin, $flow, 1))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());
    expect($flow->fresh()->name)->toBe('Renamed')
        ->and($flow->fresh()->isArchived())->toBeTrue();

    app(ChangeGameFlowAction::class)->restore($admin, $flow, 2);

    expect($flow->fresh()->isArchived())->toBeFalse()
        ->and($flow->fresh()->lock_version)->toBe(3);
});

it('archives only while another flow remains, and deletes only a flow no version used', function () {
    $draft = publishableGameDraft(1);
    $admin = $draft['admin'];

    expect(fn () => app(ChangeGameFlowAction::class)->archive($admin, $draft['flow']))->toThrow(CannotManageGameException::class);

    $second = app(ChangeGameFlowAction::class)->create($admin, $draft['game'], 'Second', GameFlowTemplate::DirectPlay);
    GameVersion::factory()->create(['game_id' => $draft['game']->id, 'content_set_id' => $draft['set']->id, 'flow_id' => $second->id]);

    expect(fn () => app(ChangeGameFlowAction::class)->delete($admin, $second))
        ->toThrow(CannotManageGameException::class, 'A published version was made from this flow. Archive it instead.');

    app(ChangeGameFlowAction::class)->delete($admin, $draft['flow']);

    expect(GameFlow::query()->find($draft['flow']->id))->toBeNull();
});
