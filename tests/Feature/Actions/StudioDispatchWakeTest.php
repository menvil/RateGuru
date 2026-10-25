<?php

use App\Actions\Studio\ApproveStudioPlanAction;
use App\Actions\Studio\ResumeStudioActionAction;
use App\Actions\Studio\RetryStudioActionAction;
use App\Actions\Studio\SwitchStudioExecutionAction;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Jobs\Studio\DispatchDueStudioActionsJob;
use App\Jobs\Studio\ExecuteStudioActionJob;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Studio\StudioDispatchWake;
use App\Support\Studio\StudioPlanSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Waking the dispatcher: once an approval is committed, once a run is done
 * with actions due waiting for it, once an administrator retries or resumes
 * an action, and once the executor is switched on — each a run of the very
 * dispatcher the minute runs, on the queue, after the commit, one waiting at
 * most. Never while the executor is off, never for a write rolled back, and
 * never in place of the minute's run, which still sends whatever is due.
 */
beforeEach(function () {
    // The database queue, not a fake: a job dispatched after the commit is
    // queued only once the transaction commits, and never when it is rolled back.
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Empties the queue as a worker would have: the jobs gone, and the lock that
 * keeps a second dispatcher run from the queue while one waits let go with them.
 */
function emptyStudioQueue(): void
{
    DB::table('jobs')->delete();
    (new UniqueLock(app(Cache::class)))->release(new DispatchDueStudioActionsJob);
}

it('wakes the dispatcher once an approval has committed — one run queued, however often it is asked for before a worker takes it', function () {
    approveStudioPlan($this->admin, approvableStudioPlan());
    app(StudioDispatchWake::class)->now();
    app(StudioDispatchWake::class)->now();

    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1)
        ->and(queuedJobs(ExecuteStudioActionJob::class))->toBe([]);
});

it('wakes nothing for an approval rolled back, nor while the executor is off', function () {
    $plan = approvableStudioPlan();

    try {
        DB::transaction(function () use ($plan): void {
            approveStudioPlan($this->admin, $plan);

            throw new RuntimeException('Taken back.');
        });
    } catch (RuntimeException) {
    }

    switchStudioExecution(false);
    approveStudioPlan($this->admin, approvableStudioPlan());

    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toBe([])
        ->and(StudioPlan::query()->whereKey($plan->id)->value('approved_at'))->toBeNull();
});

it('runs the minute\'s own pass when woken: the post due sent to the executor at once', function () {
    $action = approvedStudioAction();

    app()->call([new DispatchDueStudioActionsJob, 'handle']);

    expect($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(1)
        ->and(array_map(fn (ExecuteStudioActionJob $job): int => $job->actionId, queuedJobs(ExecuteStudioActionJob::class)))->toBe([$action->id]);
});

it('asks for the next run when one sent a whole batch, and for none when it sent less', function () {
    config(['content_studio.actions.batch_size' => 2]);
    foreach (range(1, 3) as $plan) {
        approveStudioPlan($this->admin, approvableStudioPlan());
    }
    emptyStudioQueue();
    $job = new DispatchDueStudioActionsJob;

    // Two of three sent: another run queued for the third.
    app()->call([$job, 'handle']);
    expect(queuedJobs(ExecuteStudioActionJob::class))->toHaveCount(2)
        ->and(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1);

    // The third sent, and nothing more asked for.
    emptyStudioQueue();
    app()->call([$job, 'handle']);
    expect(queuedJobs(ExecuteStudioActionJob::class))->toHaveCount(1)
        ->and(queuedJobs(DispatchDueStudioActionsJob::class))->toBe([]);
});

it('wakes the dispatcher once a run is done with actions due waiting for it, and not for one nothing waits for', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion(['publication_mode' => 'at_date', 'publish_at' => '2026-10-20 12:00:00']);
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);
    emptyStudioQueue();

    // The post: its comments, past, wait for it.
    dispatchDueStudioActions();
    emptyStudioQueue();
    runStudioActionJob($actions['post']->id);
    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1);

    // c6 has no reply: nothing waits for it.
    app()->call([new DispatchDueStudioActionsJob, 'handle']);
    emptyStudioQueue();
    runStudioActionJob($actions['c6']->id);
    expect($actions['c6']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(queuedJobs(DispatchDueStudioActionsJob::class))->toBe([]);
});

it('wakes nothing for a done run whose waiting actions are still to come: the minute finds them at their moment', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);
    $post = studioActionsByNode($plan, $saved)['post'];

    dispatchDueStudioActions();
    emptyStudioQueue();
    runStudioActionJob($post->id);

    expect($post->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(queuedJobs(DispatchDueStudioActionsJob::class))->toBe([]);
});

it('wakes the dispatcher when an administrator retries or resumes an action, and when the executor is switched on — not off', function () {
    $failed = approvedStudioAction();
    $failed->forceFill(['status' => StudioActionStatus::Failed, 'failure_code' => StudioActionFailure::UnexpectedFailure->value, 'attempts' => 1, 'finished_at' => now()])->save();
    emptyStudioQueue();

    app(RetryStudioActionAction::class)->handle($this->admin, $failed->id);
    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1);

    $held = approvedStudioAction();
    $held->forceFill(['status' => StudioActionStatus::Held, 'failure_code' => StudioActionFailure::AuthorRestricted->value, 'attempts' => 1])->save();
    emptyStudioQueue();

    app(ResumeStudioActionAction::class)->handle($this->admin, $held->id);
    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1);

    emptyStudioQueue();
    app(SwitchStudioExecutionAction::class)->handle($this->admin, false);
    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toBe([]);

    app(SwitchStudioExecutionAction::class)->handle($this->admin, true);
    expect(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1);
});

it('leaves the minute\'s run as it was: it still sends whatever is due, whether or not anything woke it', function () {
    $action = approvedStudioAction();
    emptyStudioQueue();

    expect(dispatchDueStudioActions())->toMatchArray(['enabled' => true, 'sent' => 1])
        ->and($action->fresh()->status)->toBe(StudioActionStatus::Dispatched);
});

it('approves through the same set of actions the schedule works out, and wakes once', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    ['plan' => $plan] = approvableStudioPlanWithVotes();
    $preview = app(StudioPlanSchedule::class)->of($plan, CarbonImmutable::now('UTC'));

    app(ApproveStudioPlanAction::class)->handle($this->admin, $plan, $plan->lock_version);
    $written = StudioAction::query()->where('studio_plan_id', $plan->id)->orderBy('id')->get();

    expect($written)->toHaveCount(count($preview))
        ->and($written->map(fn (StudioAction $action): array => [$action->type, $action->actor_user_id, $action->payload, $action->occurs_at->toDateTimeString(), $action->run_after->toDateTimeString()])->all())
        ->toEqual(array_map(fn ($item): array => [$item->type, $item->actorId, $item->payload, $item->occursAt->toDateTimeString(), $item->runAfter->toDateTimeString()], $preview))
        ->and(queuedJobs(DispatchDueStudioActionsJob::class))->toHaveCount(1);
});
