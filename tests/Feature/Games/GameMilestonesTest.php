<?php

use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameMilestone;
use App\Events\Games\GameMilestoneReached;
use App\Livewire\Games\PlayGame;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\ProjectSettings;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
});

/** A guest's browser playing the live game through every round, picking the first option. */
function playEveryRound(string $slug, int $rounds, string $token = 'milestonemilestonemilestonemilestone00'): Testable
{
    $play = Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->test(PlayGame::class, ['slug' => $slug])
        ->call('init')->call('continue');

    for ($round = 0; $round < $rounds; $round++) {
        $play->call('choose', 0)->call('next');
    }

    return $play;
}

it('marks halfway at the middle round, whatever the length of the game', function (int $rounds, int $middle) {
    $draft = publishableGameDraft($rounds);
    liveGame($draft);

    playEveryRound($draft['game']->slug, $rounds);

    expect(GameEvent::query()->where('type', GameEventType::Halfway)->pluck('round_index')->all())->toBe([$middle]);
})->with([
    'one round' => [1, 0],
    'two rounds' => [2, 0],
    'three rounds' => [3, 1],
    'five rounds' => [5, 2],
]);

it('tells the tracker each moment of a play as it is reached', function () {
    Event::fake([GameMilestoneReached::class]);
    $draft = publishableGameDraft(3);
    liveGame($draft);

    playEveryRound($draft['game']->slug, 3);

    $reached = [];
    Event::assertDispatched(GameMilestoneReached::class, function (GameMilestoneReached $event) use (&$reached): bool {
        $reached[] = $event->milestone instanceof GameMilestone ? $event->milestone->value : $event->milestone;

        return true;
    });

    expect($reached)->toBe(['started', 'halfway', 'completed']);
});

it('marks the goal reached once per attempt, and a replay reaches its own', function () {
    $draft = publishableGameDraft(1);
    $draft['flow']->forceFill(['goal' => 'completed'])->save();
    liveGame($draft);

    $play = playEveryRound($draft['game']->slug, 1);
    $first = GameAttempt::query()->sole();

    expect($first->goal_reached_at)->not->toBeNull()
        ->and(GameEvent::query()->where('type', GameEventType::GoalReached)->count())->toBe(1);

    $play->call('replay')->call('choose', 0)->call('next');

    expect(GameAttempt::query()->whereNotNull('goal_reached_at')->count())->toBe(2)
        ->and(GameEvent::query()->where('type', GameEventType::GoalReached)->count())->toBe(2)
        ->and(GameEvent::query()->where('type', GameEventType::GoalReached)->where('attempt_id', $first->id)->count())->toBe(1);
});

it('credits a replay to the play the visitor came back from', function () {
    $draft = publishableGameDraft(1);
    $draft['flow']->forceFill(['goal' => 'replayed'])->save();
    liveGame($draft);

    playEveryRound($draft['game']->slug, 1)->call('replay');
    $attempts = GameAttempt::query()->orderBy('id')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->goal_reached_at)->not->toBeNull()
        ->and($attempts[1]->goal_reached_at)->toBeNull();
});

it('credits a next game started to the play whose Next Game sent the visitor there, past another game’s since', function (bool $detour) {
    Event::fake([GameMilestoneReached::class]);
    [$a, $b, $c, $d] = [publishableGameDraft(1), publishableGameDraft(1), publishableGameDraft(1), publishableGameDraft(1)];
    liveGame($b);
    liveGame($d);
    replaceFlowSteps($a['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::NextGame], $b['game']);
    liveGame($a);
    replaceFlowSteps($c['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::NextGame], $d['game']);
    liveGame($c);
    $token = str_repeat('n', 40);

    playEveryRound($a['game']->slug, 1, $token)->call('continue')->call('leave', 'next')
        ->assertRedirect(route('games.play', ['slug' => $b['game']->slug]));

    // Another game's Next Game taken later, to a game other than the one started.
    if ($detour) {
        $this->travel(1)->minutes();
        playEveryRound($c['game']->slug, 1, $token)->call('continue')->call('leave', 'next')
            ->assertRedirect(route('games.play', ['slug' => $d['game']->slug]));
    }

    $this->travel(5)->minutes();
    Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->test(PlayGame::class, ['slug' => $b['game']->slug])
        ->call('init')->call('continue');

    $credited = Event::dispatched(GameMilestoneReached::class, fn (GameMilestoneReached $event): bool => $event->milestone === GameMilestone::NextGame)
        ->map(fn (array $arguments): int => $arguments[0]->attemptId)
        ->values()
        ->all();

    expect(GameAttempt::query()->where('game_id', $b['game']->id)->exists())->toBeTrue()
        ->and($credited)->toBe([GameAttempt::query()->where('game_id', $a['game']->id)->sole()->id]);
})->with([
    'straight from the game that sent them' => [false],
    'another game’s Next Game taken since' => [true],
]);

it('freezes the flow’s goal into the version it publishes', function () {
    $draft = publishableGameDraft(1);
    $draft['flow']->forceFill(['goal' => 'shared'])->save();

    $version = liveGame($draft);

    expect($version->fresh()->goal?->value)->toBe('shared')
        ->and($version->fresh()->read()->flowGoal?->value)->toBe('shared');
});

it('publishes a flow with no goal with the goal its steps are there for', function () {
    $draft = publishableGameDraft(1);

    $version = liveGame($draft);

    expect($version->fresh()->goal?->value)->toBe('account');
});
