<?php

use App\Actions\Studio\CancelStudioActionAction;
use App\Actions\Studio\ResumeStudioActionAction;
use App\Actions\Studio\RetryStudioActionAction;
use App\Actions\Studio\StopStudioPlanAction;
use App\Actions\Studio\SwitchStudioExecutionAction;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Studio\StudioExecutionSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * What an administrator does in the Action queue: Retry a failed action,
 * Resume a held one, Cancel one not yet run with its branch, Stop what is
 * left of a plan, and switch the executor — each asked again on the locked
 * rows, refused with its reason, and never taking back anything written.
 */
beforeEach(function () {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Moves an action as a run or the dispatcher would have left it. */
function leftAs(StudioAction $action, StudioActionStatus $status, ?StudioActionFailure $failure = null, int $attempts = 1): StudioAction
{
    $action->forceFill([
        'status' => $status,
        'failure_code' => $failure?->value,
        'attempts' => $attempts,
        'finished_at' => in_array($status, [StudioActionStatus::Failed, StudioActionStatus::Cancelled, StudioActionStatus::Done], true) ? now() : null,
    ])->save();

    return $action->fresh();
}

/** What a refusal says, as its reason and the status it found. */
function managementRefusal(Closure $operation): array
{
    try {
        $operation();
    } catch (CannotManageStudioActionException $refusal) {
        return [$refusal->reason, $refusal->status];
    }

    throw new RuntimeException('Nothing was refused.');
}

it('retries a failed action from the start — pending, its attempts counted afresh, its dates kept — and runs it once', function () {
    $action = leftAs(approvedStudioAction(), StudioActionStatus::Failed, StudioActionFailure::AttemptsExhausted, attempts: 3);
    $before = $action->only(['occurs_at', 'run_after']);

    app(RetryStudioActionAction::class)->handle($this->admin, $action->id);

    expect($action->fresh())->status->toBe(StudioActionStatus::Pending)->attempts->toBe(0)->deferrals->toBe(0)->failure_code->toBeNull()->finished_at->toBeNull()
        ->and($action->fresh()->only(['occurs_at', 'run_after']))->toEqual($before);

    runStudioRound();

    expect($action->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(1)
        ->and(Post::query()->count())->toBe(1);
});

it('resumes a held action, which a persona still restricted holds again rather than writing past it', function () {
    $action = leftAs(approvedStudioAction(), StudioActionStatus::Held, StudioActionFailure::AuthorRestricted);

    app(ResumeStudioActionAction::class)->handle($this->admin, $action->id);
    expect($action->fresh())->status->toBe(StudioActionStatus::Pending)->failure_code->toBeNull();

    runStudioRound();
    expect($action->fresh()->status)->toBe(StudioActionStatus::Done);
});

it('never retries an action done, cancelled or not failed, nor resumes one not held', function (StudioActionStatus $status) {
    $action = leftAs(approvedStudioAction(), $status);

    expect(managementRefusal(fn () => app(RetryStudioActionAction::class)->handle($this->admin, $action->id)))->toBe(['status', $status])
        ->and($action->fresh()->status)->toBe($status);

    if ($status !== StudioActionStatus::Held) {
        expect(managementRefusal(fn () => app(ResumeStudioActionAction::class)->handle($this->admin, $action->id)))->toBe(['status', $status]);
    }
})->with([
    'done' => StudioActionStatus::Done,
    'cancelled' => StudioActionStatus::Cancelled,
    'pending' => StudioActionStatus::Pending,
    'running' => StudioActionStatus::Running,
    'held' => StudioActionStatus::Held,
]);

it('retries nothing whose parent is not done: it would only wait, or be cancelled with it', function () {
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);
    leftAs($actions['c1'], StudioActionStatus::Failed, StudioActionFailure::UnexpectedFailure);

    expect(managementRefusal(fn () => app(RetryStudioActionAction::class)->handle($this->admin, $actions['c1']->id)))->toBe(['parent_not_done', null])
        ->and($actions['c1']->fresh()->status)->toBe(StudioActionStatus::Failed);
});

it('cancels an action not yet run with every action of its branch still waiting, settles the plan, and a job delivered afterwards runs nothing', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);
    dispatchDueStudioActions();
    expect($actions['post']->fresh()->status)->toBe(StudioActionStatus::Dispatched);

    $branch = app(CancelStudioActionAction::class)->handle($this->admin, $actions['post']->id);

    expect($actions['post']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('cancelled_by_administrator')
        ->and($branch)->toHaveCount(6)
        ->and(collect($actions)->except('post')->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Cancelled && $action->fresh()->failure_code === 'parent_cancelled'))->toBeTrue()
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Cancelled);

    // The job sent before the cancellation arrives: nothing claimed, nothing written.
    runStudioActionJob($actions['post']->id);
    expect(Post::query()->count())->toBe(0)
        ->and($actions['post']->fresh()->status)->toBe(StudioActionStatus::Cancelled);
});

it('never cancels an action done — nothing published is taken back — nor one a worker is running', function (StudioActionStatus $status) {
    $action = leftAs(approvedStudioAction(), $status);

    expect(managementRefusal(fn () => app(CancelStudioActionAction::class)->handle($this->admin, $action->id)))->toBe(['status', $status])
        ->and($action->fresh()->status)->toBe($status);
})->with([
    'done' => StudioActionStatus::Done,
    'running' => StudioActionStatus::Running,
    'cancelled already' => StudioActionStatus::Cancelled,
]);

it('cancels one comment\'s branch and leaves the published post, the other comments and the plan running', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);
    runStudioRound();

    app(CancelStudioActionAction::class)->handle($this->admin, $actions['c1']->id);

    expect($actions['post']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and($actions['c2']->fresh()->failure_code)->toBe('parent_cancelled')
        ->and($actions['c3']->fresh()->failure_code)->toBe('parent_cancelled')
        ->and($actions['c4']->fresh()->status)->toBe(StudioActionStatus::Pending)
        ->and(Post::query()->count())->toBe(1)
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Running);
});

it('stops what is left of a plan whose post is published: its comments and votes still to come cancelled, the post and what ran kept, the plan completed', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);
    runStudioRound();
    Carbon::setTestNow('2026-10-25 12:06:00');
    runStudioRound();
    expect($actions['c1']->fresh()->status)->toBe(StudioActionStatus::Done);

    $cancelled = app(StopStudioPlanAction::class)->handle($this->admin, $plan->id);

    expect($cancelled)->toHaveCount(5)
        ->and(collect($actions)->except(['post', 'c1'])->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Cancelled && $action->fresh()->failure_code === 'cancelled_by_administrator'))->toBeTrue()
        ->and(Post::query()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(1)
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed);

    // Nothing left to stop.
    expect(managementRefusal(fn () => app(StopStudioPlanAction::class)->handle($this->admin, $plan->id)))->toBe(['plan_not_running', null]);
});

it('stops a plan whose post never ran, which then never publishes', function () {
    $plan = approvableStudioPlan(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2030-01-01 10:00:00')]);
    approveStudioPlan($this->admin, $plan);

    app(StopStudioPlanAction::class)->handle($this->admin, $plan->id);

    expect($plan->fresh()->status)->toBe(StudioPlanStatus::Cancelled)
        ->and(StudioAction::query()->sole()->status)->toBe(StudioActionStatus::Cancelled);
});

it('refuses every operation, and the switch, to anybody who may not manage plans — changing nothing', function (Closure $who) {
    $user = $who();
    $failed = leftAs(approvedStudioAction(), StudioActionStatus::Failed, StudioActionFailure::UnexpectedFailure);

    foreach ([
        fn () => app(RetryStudioActionAction::class)->handle($user, $failed->id),
        fn () => app(ResumeStudioActionAction::class)->handle($user, $failed->id),
        fn () => app(CancelStudioActionAction::class)->handle($user, $failed->id),
        fn () => app(StopStudioPlanAction::class)->handle($user, $failed->studio_plan_id),
        fn () => app(SwitchStudioExecutionAction::class)->handle($user, false),
    ] as $operation) {
        expect(managementRefusal($operation))->toBe(['not_allowed', null]);
    }

    expect($failed->fresh()->status)->toBe(StudioActionStatus::Failed)
        ->and(app(StudioExecutionSwitch::class)->isOn())->toBeTrue();
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create()],
]);

it('switches the executor on and off with every other setting and flag kept as stored', function () {
    switchStudioExecution(false);
    $flags = app(ProjectSettingsManager::class)->current()->featureFlag('show_comments');

    app(SwitchStudioExecutionAction::class)->handle($this->admin, true);
    expect(app(StudioExecutionSwitch::class)->isOn())->toBeTrue()
        ->and(app(ProjectSettingsManager::class)->current()->featureFlag('show_comments'))->toBe($flags);

    app(SwitchStudioExecutionAction::class)->handle($this->admin, false);
    expect(app(StudioExecutionSwitch::class)->isOn())->toBeFalse();

    // Off: an action sent before claims nothing.
    $action = approvedStudioAction();
    $action->forceFill(['status' => StudioActionStatus::Dispatched, 'attempts' => 1, 'dispatched_at' => now()])->save();
    runStudioActionJob($action->id);
    expect($action->fresh()->status)->toBe(StudioActionStatus::Dispatched)
        ->and(Post::query()->count())->toBe(0);
});
