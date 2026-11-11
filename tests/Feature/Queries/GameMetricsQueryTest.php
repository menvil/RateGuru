<?php

use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\User;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A game's figures, read from what playing it recorded: visitors from `view`
 * events, each person once — a guest by the cookie's hash, a player by the
 * account — and everything else from the attempts started in the window,
 * however late they finished. Split by version, flow or content set, a person
 * who saw two versions is one visitor in all and one in each. The same on
 * every database engine.
 */
function metricsQuery(): GameMetricsQuery
{
    return app(GameMetricsQuery::class);
}

function metricsScope(GameVersion $version, GameAudience $audience = GameAudience::Anonymous, ?int $versionId = null): GameMetricsScope
{
    return new GameMetricsScope((int) $version->game_id, $audience, CarbonImmutable::parse('2026-11-01 00:00:00'), CarbonImmutable::parse('2026-11-09 00:00:00'), $versionId);
}

/** A `view` of the version by a guest (a hash) or a player (a user), or another event with its detail. */
function metricsView(GameVersion $version, string|User $who, string $at = '2026-11-05 12:00:00', GameEventType $type = GameEventType::View, ?GameAttempt $attempt = null, ?string $provider = null): void
{
    (new GameEvent)->forceFill([
        'game_id' => $version->game_id,
        'version_id' => $version->id,
        'attempt_id' => $attempt?->id,
        'audience' => $who instanceof User ? GameAudience::Authenticated : GameAudience::Anonymous,
        'user_id' => $who instanceof User ? $who->id : null,
        'visitor_hash' => $who instanceof User ? null : hash('sha256', $who),
        'type' => $type,
        'provider' => $provider,
        'occurred_at' => $at,
    ])->save();
}

/** An attempt at the version by a guest (a hash) or a player (a user), started at a moment. */
function metricsAttempt(GameVersion $version, string|User $who, string $at = '2026-11-05 12:00:00', array $attributes = []): GameAttempt
{
    return GameAttempt::factory()->create([
        'version_id' => $version->id,
        'audience' => $who instanceof User ? GameAudience::Authenticated : GameAudience::Anonymous,
        'user_id' => $who instanceof User ? $who->id : null,
        'visitor_hash' => $who instanceof User ? null : hash('sha256', $who),
        'started_at' => $at,
        'last_activity_at' => $at,
        ...$attributes,
    ]);
}

function metricsCompleted(string $profile = 'oracle'): array
{
    return ['status' => GameAttemptStatus::Completed, 'completed_at' => '2026-11-05 12:05:00', 'matches' => 1, 'score' => 1, 'profile_key' => $profile];
}

/** A second version of the game, from a flow of its own. */
function metricsSecondVersion(array $draft): GameVersion
{
    $flow = GameFlow::factory()->create(['game_id' => $draft['game']->id]);

    foreach ($draft['flow']->steps()->get() as $step) {
        GameFlowStep::factory()->type($step->type)->create(['flow_id' => $flow->id, 'position' => $step->position]);
    }

    return app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game']->fresh(), $draft['set']->id, $flow->id);
}

afterEach(function () {
    Carbon::setTestNow();
});

it('counts each visitor once, in the window and the audience asked for', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $player = User::factory()->create();

    metricsView($version, 'guest-a');
    metricsView($version, 'guest-a', '2026-11-06 09:00:00');
    metricsView($version, 'guest-b');
    // Outside the window, before and at its end.
    metricsView($version, 'guest-c', '2026-10-31 23:59:59');
    metricsView($version, 'guest-d', '2026-11-09 00:00:00');
    // Not a view.
    metricsView($version, 'guest-e', type: GameEventType::StepViewed);
    metricsView($version, $player);
    metricsView($version, $player);
    // Another game's visitor.
    metricsView(liveGame(publishableGameDraft(rounds: 1)), 'guest-f');

    expect(metricsQuery()->funnels(metricsScope($version))[0]->visitors)->toBe(2)
        ->and(metricsQuery()->funnels(metricsScope($version, GameAudience::Authenticated))[0]->visitors)->toBe(1);
});

it('counts the attempts started in the window, however late they finished, what they led to, and the people behind them', function () {
    $version = liveGame(publishableGameDraft(rounds: 2));
    $newcomer = User::factory()->create();
    $returning = User::factory()->create();

    $completed = metricsAttempt($version, 'guest-a', attributes: metricsCompleted());
    // Started in the window and finished after it: completed all the same.
    metricsAttempt($version, 'guest-a', '2026-11-08 23:59:00', [...metricsCompleted(), 'completed_at' => '2026-11-09 00:03:00']);
    $registered = metricsAttempt($version, 'guest-b', attributes: [...metricsCompleted(), 'user_id' => $newcomer->id, 'account_outcome' => GameAccountOutcome::Registered]);
    // The same new account from a second attempt is one account.
    metricsAttempt($version, 'guest-b', attributes: ['user_id' => $newcomer->id, 'account_outcome' => GameAccountOutcome::Registered]);
    $signedIn = metricsAttempt($version, 'guest-c', attributes: ['user_id' => $returning->id, 'account_outcome' => GameAccountOutcome::SignedIn]);
    // Started before the window.
    metricsAttempt($version, 'guest-d', '2026-10-30 12:00:00', metricsCompleted());

    GameEmailCapture::query()->forceCreate(['attempt_id' => $completed->id, 'game_id' => $version->game_id, 'email' => 'a@example.test', 'captured_at' => now()]);
    metricsView($version, 'guest-a', type: GameEventType::ShareClicked, attempt: $completed);
    metricsView($version, 'guest-a', type: GameEventType::ShareClicked, attempt: $completed);
    metricsView($version, 'guest-b', type: GameEventType::ShareClicked, attempt: $registered);
    // Signing up begun: by email, then again; logging in, and Google too.
    metricsView($version, 'guest-b', type: GameEventType::RegistrationCtaClicked, attempt: $registered, provider: 'email');
    metricsView($version, 'guest-b', type: GameEventType::RegistrationCtaClicked, attempt: $registered, provider: 'email');
    metricsView($version, 'guest-c', type: GameEventType::RegistrationCtaClicked, attempt: $signedIn, provider: 'login');
    metricsView($version, 'guest-c', type: GameEventType::RegistrationCtaClicked, attempt: $signedIn, provider: 'google');

    expect(metricsQuery()->funnels(metricsScope($version))[0])->toEqual(new GameFunnel(
        visitors: 0,
        started: 5,
        completed: 3,
        emails: 1,
        registered: 1,
        signedIn: 1,
        shared: 2,
        replayers: 2,
        starters: 3,
        finishers: 2,
        emailers: 1,
        sharers: 2,
        signUpStarters: 2,
    ))
        ->and(metricsQuery()->signUpMethods(metricsScope($version)))->toBe(['email' => 1, 'google' => 1, 'login' => 1]);
});

it('splits the figures by version, flow and content set, a person once in each', function () {
    $draft = publishableGameDraft(rounds: 2);
    $first = liveGame($draft);
    $second = metricsSecondVersion($draft);

    metricsView($first, 'guest-a');
    metricsView($second, 'guest-a');
    metricsView($second, 'guest-b');
    metricsAttempt($first, 'guest-a', attributes: metricsCompleted());
    metricsAttempt($second, 'guest-b');

    $byVersion = metricsQuery()->funnels(metricsScope($first), 'version');
    $byFlow = metricsQuery()->funnels(metricsScope($first), 'flow');
    $bySet = metricsQuery()->funnels(metricsScope($first), 'set');

    expect(array_keys($byVersion))->toBe([$first->id, $second->id])
        ->and([$byVersion[$first->id]->visitors, $byVersion[$first->id]->started, $byVersion[$first->id]->completed])->toBe([1, 1, 1])
        ->and([$byVersion[$second->id]->visitors, $byVersion[$second->id]->started, $byVersion[$second->id]->completed])->toBe([2, 1, 0])
        ->and(array_keys($byFlow))->toBe([(int) $first->flow_id, (int) $second->flow_id])
        // One set: guest-a saw both versions, and is one visitor of the set.
        ->and(array_keys($bySet))->toBe([(int) $first->content_set_id])
        ->and([$bySet[$first->content_set_id]->visitors, $bySet[$first->content_set_id]->started])->toBe([2, 2])
        ->and(metricsQuery()->funnels(metricsScope($first))[0]->visitors)->toBe(2)
        // One version alone.
        ->and(metricsQuery()->funnels(metricsScope($first, versionId: $second->id))[0]->started)->toBe(1);
});

it('refuses to split the figures by anything else', function () {
    $version = liveGame(publishableGameDraft(rounds: 1));

    metricsQuery()->funnels(metricsScope($version), 'device');
})->throws(InvalidArgumentException::class);

it('counts how many started attempts answered each round, and the completed attempts by result', function () {
    $version = liveGame(publishableGameDraft(rounds: 3));
    $answer = fn (GameAttempt $attempt, int $round) => GameAnswer::query()->forceCreate([
        'attempt_id' => $attempt->id, 'round_index' => $round, 'round_key' => "r{$round}", 'option_position' => 0, 'outcome' => 'correct', 'points' => 1, 'answered_at' => now(),
    ]);

    foreach (['guest-a', 'guest-b', 'guest-c'] as $index => $guest) {
        $attempt = metricsAttempt($version, $guest, attributes: $index === 0 ? metricsCompleted('oracle') : ($index === 1 ? metricsCompleted('rebel') : []));

        foreach (range(0, $index === 2 ? 0 : 2) as $round) {
            $answer($attempt, $round);
        }
    }

    // Started before the window: not counted.
    $answer(metricsAttempt($version, 'guest-d', '2026-10-30 12:00:00', metricsCompleted('oracle')), 0);

    expect(metricsQuery()->reach(metricsScope($version), $version->id))->toBe([0 => 3, 1 => 2, 2 => 2])
        ->and(metricsQuery()->results(metricsScope($version), $version->id))->toEqualCanonicalizing(['oracle' => 1, 'rebel' => 1]);
});

it('counts the attempts started from each traffic period', function () {
    $draft = publishableGameDraft(rounds: 1);
    $version = liveGame($draft);
    $period = GameTrafficPeriod::query()->where('game_id', $version->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();
    $assignment = GameAssignment::query()->forceCreate([
        'game_id' => $version->game_id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', 'guest-a'),
        'version_id' => $version->id, 'period_id' => $period->id, 'assigned_at' => now(), 'last_seen_at' => now(),
    ]);

    metricsAttempt($version, 'guest-a', attributes: ['assignment_id' => $assignment->id, ...metricsCompleted()]);
    metricsAttempt($version, 'guest-a', attributes: ['assignment_id' => $assignment->id]);
    // A replay with no assignment belongs to no period.
    metricsAttempt($version, 'guest-a');

    expect(metricsQuery()->periods((int) $version->game_id))->toBe([$period->id => ['started' => 2, 'completed' => 1]]);
});

it('reads the library’s columns for each game with something in the window', function () {
    $first = liveGame(publishableGameDraft(rounds: 1));
    $second = liveGame(publishableGameDraft(rounds: 1));
    $quiet = liveGame(publishableGameDraft(rounds: 1));
    $newcomer = User::factory()->create();

    metricsView($first, 'guest-a');
    metricsView($first, 'guest-b');
    metricsView($first, User::factory()->create());
    metricsAttempt($first, 'guest-a', attributes: [...metricsCompleted(), 'user_id' => $newcomer->id, 'account_outcome' => GameAccountOutcome::Registered]);
    metricsAttempt($first, 'guest-b');
    metricsAttempt($second, 'guest-c', attributes: metricsCompleted());
    metricsAttempt($quiet, 'guest-d', '2026-10-01 12:00:00');

    expect(metricsQuery()->library([(int) $first->game_id, (int) $second->game_id, (int) $quiet->game_id], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-09')))->toBe([
        (int) $first->game_id => ['started' => 2, 'completed' => 1, 'visitors' => 2, 'registered' => 1],
        (int) $second->game_id => ['started' => 1, 'completed' => 1, 'visitors' => 0, 'registered' => 0],
    ])->and(metricsQuery()->library([], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-09')))->toBe([]);
});

it('takes a window that ends after it starts', function () {
    new GameMetricsScope(1, GameAudience::Anonymous, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
})->throws(InvalidArgumentException::class);
