<?php

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Jobs\Studio\ExecuteStudioActionJob;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The dispatcher `studio:dispatch-due` runs every minute: what is due is sent,
 * what was lost on its way or left by a dead worker is sent again, every send
 * counts one attempt and no more, and nothing is ever sent past the limit —
 * nor at all while the executor is switched off. The crashes the executor
 * must survive end, after one recovery, with one post and two attempts.
 */
beforeEach(function () {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The ids of the actions the queue holds a job for, in order. */
function queuedStudioActionIds(): array
{
    return array_map(fn (ExecuteStudioActionJob $job): int => $job->actionId, queuedJobs(ExecuteStudioActionJob::class));
}

it('changes and sends nothing while the executor is switched off', function () {
    switchStudioExecution(false);
    $action = approvedStudioAction();
    $before = DB::table('studio_plan_actions')->where('id', $action->id)->first();

    expect(dispatchDueStudioActions())->toBe(['enabled' => false, 'sent' => 0, 'resent' => 0, 'exhausted' => 0])
        ->and(DB::table('studio_plan_actions')->where('id', $action->id)->first())->toEqual($before)
        ->and(queuedStudioActionIds())->toBe([]);
});

it('sends what is due, once: Dispatched, one attempt counted, its job carrying the id', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();

    expect(dispatchDueStudioActions())->toMatchArray(['enabled' => true, 'sent' => 1])
        ->and($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(1)
        ->and($action->fresh()->dispatched_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and(queuedStudioActionIds())->toBe([$action->id]);

    // A second run, or a second dispatcher, sends nothing more.
    expect(dispatchDueStudioActions()['sent'])->toBe(0)
        ->and($action->fresh()->attempts)->toBe(1)
        ->and(queuedStudioActionIds())->toBe([$action->id]);
});

it('sends nothing before its time, and the oldest first, a batch at a time', function () {
    config(['content_studio.actions.batch_size' => 2]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $future = approvedStudioAction(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-21 00:00:00')]);
    $actions = collect(range(1, 3))->map(fn (int $index): StudioAction => approvedStudioAction(['title' => "Harbour {$index}"]));
    // Due at different moments: the last made is the oldest.
    $actions[2]->forceFill(['run_after' => '2026-10-20 09:00:00'])->save();
    $actions[1]->forceFill(['run_after' => '2026-10-20 09:30:00'])->save();

    expect(dispatchDueStudioActions()['sent'])->toBe(2)
        ->and(queuedStudioActionIds())->toBe([$actions[2]->id, $actions[1]->id])
        ->and(dispatchDueStudioActions()['sent'])->toBe(1)
        ->and(queuedStudioActionIds())->toBe([$actions[2]->id, $actions[1]->id, $actions[0]->id])
        ->and($future->fresh()->status)->toBe(StudioActionStatus::Pending);
});

it('sends no kind of action nothing runs yet, and none waiting for a parent not done', function () {
    $post = approvedStudioAction();
    $comment = StudioAction::factory()->childOf($post, StudioActionType::CreateComment)->create(['actor_user_id' => $post->actor_user_id]);
    // A kind that runs, still waiting for its parent.
    $child = StudioAction::factory()->childOf($post, StudioActionType::CreatePost)->create(['actor_user_id' => $post->actor_user_id]);

    dispatchDueStudioActions();

    expect(queuedStudioActionIds())->toBe([$post->id])
        ->and($comment->fresh()->status)->toBe(StudioActionStatus::Pending)
        ->and($child->fresh()->status)->toBe(StudioActionStatus::Pending);

    $post->forceFill(['status' => StudioActionStatus::Done])->save();
    dispatchDueStudioActions();

    expect(queuedStudioActionIds())->toBe([$post->id, $child->id])
        ->and($comment->fresh()->status)->toBe(StudioActionStatus::Pending);
});

it('sends a lost job again once it has been unclaimed long enough, counting one more attempt', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    dispatchDueStudioActions();

    Carbon::setTestNow('2026-10-20 10:14:59');
    expect(dispatchDueStudioActions()['resent'])->toBe(0);

    Carbon::setTestNow('2026-10-20 10:15:00');
    expect(dispatchDueStudioActions()['resent'])->toBe(1)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(2)
        ->and($action->fresh()->dispatched_at->toDateTimeString())->toBe('2026-10-20 10:15:00');
});

it('gives up, failed, on an action sent as often as it may be, and leaves its plan for an administrator', function () {
    config(['content_studio.actions.max_attempts' => 2]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    dispatchDueStudioActions();
    Carbon::setTestNow('2026-10-20 10:20:00');
    dispatchDueStudioActions();
    Carbon::setTestNow('2026-10-20 10:40:00');

    expect(dispatchDueStudioActions()['exhausted'])->toBe(1)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('attempts_exhausted')->attempts->toBe(2)
        ->and($action->fresh()->finished_at)->not->toBeNull()
        ->and(StudioPlan::query()->findOrFail($action->studio_plan_id)->status)->toBe(StudioPlanStatus::Approved)
        ->and(queuedStudioActionIds())->toBe([$action->id, $action->id]);

    Carbon::setTestNow('2026-10-21 10:00:00');
    expect(dispatchDueStudioActions())->toMatchArray(['sent' => 0, 'resent' => 0, 'exhausted' => 0]);
});

it('keeps an action sent whose job the queue refused after the commit, and sends it again once found lost', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    // A queue that refuses the push, which waits for the commit as a Redis
    // push does: PostgreSQL commits, then Redis is down.
    app('queue')->extend('refusing', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new class extends NullQueue
            {
                public function push($job, $data = '', $queue = null)
                {
                    return $this->enqueueUsing($job, $this->createPayload($job, $queue ?? 'default', $data), $queue, null, function (): never {
                        throw new RuntimeException('The queue refused the job.');
                    });
                }
            };
        }
    });
    config(['queue.connections.refusing' => ['driver' => 'refusing'], 'queue.default' => 'refusing']);

    expect(dispatchDueStudioActions()['sent'])->toBe(0)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(1);

    config(['queue.default' => 'database']);
    Carbon::setTestNow('2026-10-20 10:15:00');

    expect(dispatchDueStudioActions()['resent'])->toBe(1)
        ->and($action->fresh()->attempts)->toBe(2)
        ->and(queuedStudioActionIds())->toBe([$action->id]);
});

it('survives the crashes it must: one recovery, one post, two attempts', function (Closure $crash) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    dispatchDueStudioActions();

    $crash($action);

    // Recovery: past the threshold the action was left at, it is sent again.
    Carbon::setTestNow('2026-10-20 10:16:00');
    expect(dispatchDueStudioActions()['resent'])->toBe(1)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(2);

    runStudioActionJob($action->id);
    // A late redelivery of the first job changes nothing.
    runStudioActionJob($action->id);

    expect(Post::query()->count())->toBe(1)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(2)
        ->and(Post::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
})->with([
    'the job lost by Redis, or delivered to a worker that does not know it' => [fn () => null],
    'the worker dead after its claim committed, before the post' => [function (StudioAction $action) {
        // The claim committed; the process never got further.
        $action->fresh()->forceFill(['status' => StudioActionStatus::Running, 'claimed_at' => now()])->save();
    }],
    'the worker dead with the post written but not committed' => [function (StudioAction $action) {
        $crashing = true;
        Post::created(function () use (&$crashing): void {
            if ($crashing) {
                throw new QueryException('pgsql', 'insert into "posts"', [], new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly'));
            }
        });

        try {
            runStudioActionJob($action->id);
        } catch (QueryException) {
            // The process is gone; its transaction rolled back with it.
        }

        $crashing = false;
        expect(Post::query()->exists())->toBeFalse()
            ->and($action->fresh()->status)->toBe(StudioActionStatus::Running);
    }],
]);

it('sends a running action again only once a live run could no longer hold it', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    dispatchDueStudioActions();
    $action->fresh()->forceFill(['status' => StudioActionStatus::Running, 'claimed_at' => now()])->save();

    Carbon::setTestNow('2026-10-20 10:02:59');
    expect(dispatchDueStudioActions()['resent'])->toBe(0);

    Carbon::setTestNow('2026-10-20 10:03:00');
    expect(dispatchDueStudioActions()['resent'])->toBe(1)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(2)->claimed_at->toBeNull();
});

it('lets a job already sent begin nothing once the executor is switched off, and sends it again once it is back on', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    dispatchDueStudioActions();
    switchStudioExecution(false);

    runStudioActionJob($action->id);

    expect($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->claimed_at->toBeNull()
        ->and(Post::query()->exists())->toBeFalse();

    switchStudioExecution(true);
    Carbon::setTestNow('2026-10-20 10:15:00');
    dispatchDueStudioActions();
    runStudioActionJob($action->id);

    expect($action->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(2)
        ->and(Post::query()->count())->toBe(1);
});
