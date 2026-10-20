<?php

use App\Actions\Studio\ExecuteStudioAction;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Jobs\Studio\ExecuteStudioActionJob;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use App\Queries\Feed\FeedQuery;
use App\Queries\UserPublicPostsQuery;
use App\Support\AbuseGuards\RateLimitKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Running an approved plan's CreatePost: one real post of its Persona,
 * written, approved and scored with the action done in one transaction, its
 * dates the plan's — and nothing written twice, whatever happens to the job.
 */
beforeEach(function () {
    // The database queue, not a fake: a job dispatched after the commit is
    // queued only once the run commits, and never when it is rolled back.
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Sends the action and runs its job, as the dispatcher and a worker would. */
function sendAndRunStudioAction(StudioAction $action): StudioAction
{
    dispatchDueStudioActions();
    runStudioActionJob($action->id);

    return $action->fresh();
}

it('publishes the plan as a real post of its Persona: written, approved, scored, the action done with it', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $admin = User::factory()->admin()->create();
    $action = approvedStudioAction(['description' => 'Boats at dawn.'], $admin);
    $plan = StudioPlan::query()->findOrFail($action->studio_plan_id);

    Carbon::setTestNow('2026-10-20 10:00:30');
    $action = sendAndRunStudioAction($action);
    $post = Post::query()->sole();

    expect($post)
        ->user_id->toBe($plan->author_user_id)
        ->title->toBe($plan->title)
        ->description->toBe('Boats at dawn.')
        ->status->toBe(PostStatus::Published)
        ->image_asset_id->toBe($plan->image_asset_id)
        ->and($post->hot_score)->toBeGreaterThan(0)
        ->and($action)
        ->status->toBe(StudioActionStatus::Done)
        ->result_type->toBe(Post::class)
        ->result_id->toBe($post->id)
        ->attempts->toBe(1)
        ->failure_code->toBeNull()
        ->and($action->finished_at->toDateTimeString())->toBe('2026-10-20 10:00:30')
        // Nothing of it is left to run.
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed)
        // Approved through the moderation it always goes through, by the plan's administrator.
        ->and(ModerationLog::query()->where('action', ModerationActionType::ApprovePost)->where('target_id', $post->id)->sole()->moderator_id)->toBe($admin->id)
        // The photo is used as it is: no copy, and still its uploader's.
        ->and(MediaAsset::query()->count())->toBe(1)
        ->and(MediaAsset::query()->sole()->owner_user_id)->toBe($plan->created_by_user_id);
});

it('gives the post the plan\'s date — now, past or future — whenever it runs, and the action its real finish', function (array $plan, string $approvedAt, string $runsAt, string $postAt) {
    Carbon::setTestNow($approvedAt);
    $action = approvedStudioAction($plan);

    Carbon::setTestNow($runsAt);
    $action = sendAndRunStudioAction($action);
    $post = Post::query()->sole();

    expect($action->status)->toBe(StudioActionStatus::Done)
        ->and($action->occurs_at->toDateTimeString())->toBe($postAt)
        ->and($action->finished_at->toDateTimeString())->toBe(CarbonImmutable::parse($runsAt)->toDateTimeString())
        // The plan's date, through the approval and the score: never the time it ran.
        ->and($post->created_at->toDateTimeString())->toBe($postAt)
        ->and($post->updated_at->toDateTimeString())->toBe($postAt)
        ->and($post->published_at->toDateTimeString())->toBe($postAt);
})->with([
    'now, run a minute late' => [[], '2026-10-20 10:00:00', '2026-10-20 10:01:00', '2026-10-20 10:00:00'],
    'on a past date' => [['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2021-01-01 12:00:00')], '2026-10-20 10:00:00', '2026-10-20 10:00:30', '2021-01-01 12:00:00'],
    'on a future date, run late' => [['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-11-01 08:30:00')], '2026-10-20 10:00:00', '2026-11-01 08:35:00', '2026-11-01 08:30:00'],
]);

it('keeps a future post back until its date, sending and claiming nothing earlier', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-11-01 08:30:00')]);

    expect(dispatchDueStudioActions()['sent'])->toBe(0);

    // Sent early by hand, it is still not claimed.
    $action->forceFill(['status' => StudioActionStatus::Dispatched, 'attempts' => 1, 'dispatched_at' => now()])->save();
    runStudioActionJob($action->id);

    expect($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->claimed_at->toBeNull()
        ->and(Post::query()->exists())->toBeFalse();
});

it('places a historical post at its date in the feed and on its Persona\'s profile', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2022-06-01 12:00:00')]);
    $persona = User::query()->findOrFail($action->actor_user_id);
    $older = Post::factory()->published()->for($persona)->create(['published_at' => '2021-01-01 00:00:00']);
    $newer = Post::factory()->published()->for($persona)->create(['published_at' => '2024-01-01 00:00:00']);

    sendAndRunStudioAction($action);
    $post = Post::query()->whereKey($action->fresh()->result_id)->sole();

    expect(app(FeedQuery::class)->get(sort: 'newest')->modelKeys())->toBe([$newer->id, $post->id, $older->id])
        ->and(app(UserPublicPostsQuery::class)->forProfile($persona)->getCollection()->modelKeys())->toBe([$newer->id, $post->id, $older->id]);
});

it('tells the Persona its post is approved, and its followers only once committed', function () {
    $action = approvedStudioAction();

    sendAndRunStudioAction($action);
    $post = Post::query()->sole();

    expect(User::query()->findOrFail($action->actor_user_id)->notifications()->where('type', PostApprovedNotification::class)->count())->toBe(1)
        ->and(array_map(fn (NotifyFollowersAboutNewPostJob $job): int => $job->postId, queuedJobs(NotifyFollowersAboutNewPostJob::class)))->toBe([$post->id])
        ->and(queuedJobs(GenerateMediaVariantsJob::class))->toHaveCount(1);
});

it('applies the action once: a job delivered again after it is done writes, notifies and counts nothing', function () {
    $action = approvedStudioAction();
    $action = sendAndRunStudioAction($action);
    $post = Post::query()->sole();
    $scored = $post->hot_score;

    runStudioActionJob($action->id);
    runStudioActionJob($action->id);

    expect(Post::query()->count())->toBe(1)
        ->and($action->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(1)->result_id->toBe($post->id)
        ->and($post->fresh()->hot_score)->toBe($scored)
        ->and(User::query()->findOrFail($action->actor_user_id)->notifications()->count())->toBe(1)
        ->and(queuedJobs(NotifyFollowersAboutNewPostJob::class))->toHaveCount(1);
});

it('holds, cancels or fails the action as its Persona or approver now stand, publishing nothing', function (Closure $change, StudioActionStatus $status, string $code, StudioPlanStatus $planStatus) {
    $admin = User::factory()->admin()->create();
    $action = approvedStudioAction([], $admin);
    $change(User::query()->findOrFail($action->actor_user_id), $admin);

    $action = sendAndRunStudioAction($action);

    expect($action)->status->toBe($status)->failure_code->toBe($code)
        ->and(Post::query()->exists())->toBeFalse()
        ->and(ModerationLog::query()->where('action', ModerationActionType::ApprovePost)->exists())->toBeFalse()
        ->and(StudioPlan::query()->findOrFail($action->studio_plan_id)->status)->toBe($planStatus)
        ->and(queuedJobs(NotifyFollowersAboutNewPostJob::class))->toBe([]);
})->with([
    'a limited Persona, held for an administrator' => [fn (User $persona) => $persona->forceFill(['status' => UserStatus::Limited])->save(), StudioActionStatus::Held, 'author_restricted', StudioPlanStatus::Running],
    'a shadowbanned Persona, held for an administrator' => [fn (User $persona) => $persona->forceFill(['status' => UserStatus::Shadowbanned])->save(), StudioActionStatus::Held, 'author_restricted', StudioPlanStatus::Running],
    'a banned Persona, cancelled' => [fn (User $persona) => $persona->forceFill(['status' => UserStatus::Banned])->save(), StudioActionStatus::Cancelled, 'author_banned', StudioPlanStatus::Cancelled],
    'a deleted Persona, cancelled' => [fn (User $persona) => $persona->forceFill(['status' => UserStatus::Deleted])->save(), StudioActionStatus::Cancelled, 'author_deleted', StudioPlanStatus::Cancelled],
    'an approver no longer staff, failed' => [fn (User $persona, User $admin) => $admin->forceFill(['role' => 'user'])->save(), StudioActionStatus::Failed, 'approver_not_allowed', StudioPlanStatus::Running],
    'an approver banned, failed' => [fn (User $persona, User $admin) => $admin->forceFill(['status' => UserStatus::Banned])->save(), StudioActionStatus::Failed, 'approver_not_allowed', StudioPlanStatus::Running],
]);

it('cancels the action when what the post would use is no longer usable', function (Closure $change, string $code) {
    $action = approvedStudioAction();
    $change(StudioPlan::query()->findOrFail($action->studio_plan_id));

    $action = sendAndRunStudioAction($action);

    expect($action)->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe($code)
        ->and($action->finished_at)->not->toBeNull()
        ->and(Post::query()->where('title', 'like', 'A quiet harbour%')->exists())->toBeFalse();
})->with([
    'the photo deleted' => [fn (StudioPlan $plan) => MediaAsset::query()->whereKey($plan->image_asset_id)->delete(), 'photo_unavailable'],
    'the photo a post\'s meanwhile' => [fn (StudioPlan $plan) => Post::factory()->create(['image_asset_id' => $plan->image_asset_id]), 'photo_unavailable'],
    'the category switched off' => [function (StudioPlan $plan) {
        $category = Category::factory()->create();
        DB::table('studio_plans')->where('id', $plan->id)->update(['category_id' => $category->id]);
        $category->forceFill(['is_active' => false])->save();
    }, 'category_unavailable'],
]);

it('takes the whole run back when a write of it is not saved, then fails the action', function () {
    $action = approvedStudioAction();
    StudioAction::updating(fn (StudioAction $updated): ?bool => $updated->status === StudioActionStatus::Done ? false : null);
    $queued = recordQueuedJobClasses();

    $action = sendAndRunStudioAction($action);

    expect($action)->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('unexpected_failure')
        ->and($action->failure_reason)->toBe(RuntimeException::class)
        ->and(Post::query()->exists())->toBeFalse()
        ->and(ModerationLog::query()->exists())->toBeFalse()
        ->and(User::query()->findOrFail($action->actor_user_id)->notifications()->exists())->toBeFalse()
        // Followers never hear of a post that was taken back: their job was never even sent.
        ->and($queued->getArrayCopy())->not->toContain(NotifyFollowersAboutNewPostJob::class);
});

it('takes the whole run back when the post\'s score is not saved: no post, no approval, no notification, the action failed', function () {
    $action = approvedStudioAction();
    // The score's save alone is cancelled, as a model event cancels one: false, no exception.
    Post::updating(fn (Post $post): ?bool => $post->isDirty('hot_score') ? false : null);
    $queued = recordQueuedJobClasses();

    $action = sendAndRunStudioAction($action);

    expect($action)
        ->status->toBe(StudioActionStatus::Failed)
        ->failure_code->toBe('unexpected_failure')
        ->failure_reason->toBe(RuntimeException::class)
        ->result_id->toBeNull()
        ->and($action->finished_at)->not->toBeNull()
        ->and(Post::withTrashed()->exists())->toBeFalse()
        ->and(ModerationLog::query()->where('action', ModerationActionType::ApprovePost)->exists())->toBeFalse()
        ->and(User::query()->findOrFail($action->actor_user_id)->notifications()->exists())->toBeFalse()
        // Neither the followers nor the photo's variants hear of a post that was taken back.
        ->and($queued->getArrayCopy())->not->toContain(NotifyFollowersAboutNewPostJob::class)
        ->and($queued->getArrayCopy())->not->toContain(GenerateMediaVariantsJob::class)
        // Failed waits for an administrator, and keeps its plan Running.
        ->and(StudioPlan::query()->findOrFail($action->studio_plan_id)->status)->toBe(StudioPlanStatus::Running);
});

it('sends an action back, its date kept, while its Persona is at its posting limit — a bounded number of times', function () {
    config(['rate_limits.upload.max_attempts' => 2, 'rate_limits.upload.decay_seconds' => 600]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $persona = User::factory()->persona()->create();
    $actions = collect(range(1, 3))->map(fn (int $index): StudioAction => approvedStudioAction(['author_user_id' => $persona->id, 'title' => "Harbour {$index}"]));

    dispatchDueStudioActions();
    $actions->each(fn (StudioAction $action) => runStudioActionJob($action->id));
    $third = $actions[2]->fresh();

    expect(Post::query()->count())->toBe(2)
        ->and($third)->status->toBe(StudioActionStatus::Pending)->failure_code->toBe('rate_limited')->attempts->toBe(1)->deferrals->toBe(1)
        ->and($third->occurs_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($third->run_after->greaterThan(now()))->toBeTrue();

    // Not sent again before the limit allows.
    expect(dispatchDueStudioActions()['sent'])->toBe(0);

    Carbon::setTestNow($third->run_after->addSecond());
    dispatchDueStudioActions();
    runStudioActionJob($third->id);

    expect($third->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(2)
        ->and(Post::query()->whereKey($third->fresh()->result_id)->sole()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('publishes a backlog its Persona\'s posting limit spreads over many rounds, giving none of it up as failed deliveries', function () {
    config(['rate_limits.upload.max_attempts' => 2, 'rate_limits.upload.decay_seconds' => 600, 'content_studio.actions.max_attempts' => 3]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $persona = User::factory()->persona()->create();
    $actions = collect(range(1, 7))->map(fn (int $index): StudioAction => approvedStudioAction(['author_user_id' => $persona->id, 'title' => "Harbour {$index}"]));

    // Two posts a window, so four rounds: the last action is sent four times,
    // past max_attempts, were the sends its limit sent back counted.
    foreach (range(1, 4) as $round) {
        dispatchDueStudioActions();
        $actions->each(fn (StudioAction $action) => runStudioActionJob($action->id));
        Carbon::setTestNow(now()->addSeconds(601));
    }

    expect(Post::query()->count())->toBe(7)
        ->and($actions->map(fn (StudioAction $action): StudioActionStatus => $action->fresh()->status)->unique()->values()->all())->toBe([StudioActionStatus::Done])
        ->and($actions[6]->fresh())->attempts->toBe(4)->deferrals->toBe(3)->failure_code->toBeNull();
});

it('gives an action up, failed and rate_limited, once its Persona\'s posting limit has sent it back as often as it may', function () {
    config(['rate_limits.upload.max_attempts' => 1, 'rate_limits.upload.decay_seconds' => 600, 'content_studio.actions.max_deferrals' => 2]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $action = approvedStudioAction();
    $persona = User::query()->findOrFail($action->actor_user_id);

    // A limit that never lifts: the Persona's window is full before every run.
    foreach (range(1, 3) as $round) {
        RateLimiter::hit(RateLimitKey::userAction('upload', $persona), 600);
        dispatchDueStudioActions();
        runStudioActionJob($action->id);
        Carbon::setTestNow(now()->addSeconds(601));
    }

    expect($action->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('rate_limited')->attempts->toBe(3)->deferrals->toBe(2)
        ->and($action->fresh()->finished_at)->not->toBeNull()
        ->and(Post::query()->exists())->toBeFalse()
        ->and(dispatchDueStudioActions())->toMatchArray(['sent' => 0, 'resent' => 0, 'exhausted' => 0]);
});

it('writes nothing for an attempt recovery has already sent again', function () {
    $action = approvedStudioAction();
    dispatchDueStudioActions();
    $locks = 0;

    // Between this run's claim and its run, recovery sends the action again.
    DB::beforeExecuting(function (string $sql) use ($action, &$locks): void {
        if (preg_match('/from\s+["`]?studio_plan_actions["`]?\s+where/i', $sql) === 1 && str_contains(strtolower($sql), 'limit 1') && ++$locks === 2) {
            DB::table('studio_plan_actions')->where('id', $action->id)->update(['status' => 'dispatched', 'attempts' => 2, 'claimed_at' => null]);
        }
    });

    runStudioActionJob($action->id);

    expect(Post::query()->exists())->toBeFalse()
        ->and($action->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(2);
});

it('leaves an action running for recovery when its run loses its connection, writing nothing', function () {
    $action = approvedStudioAction();
    dispatchDueStudioActions();
    Post::created(fn () => throw new QueryException('pgsql', 'insert into "posts"', [], new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly')));

    expect(fn () => runStudioActionJob($action->id))->toThrow(QueryException::class);

    expect(Post::query()->exists())->toBeFalse()
        ->and($action->fresh())->status->toBe(StudioActionStatus::Running)->attempts->toBe(1)->claimed_at->not->toBeNull();
});

it('does nothing while the executor is switched off, nor once it is switched off between two jobs of one worker', function () {
    $first = approvedStudioAction(['title' => 'First harbour']);
    $second = approvedStudioAction(['title' => 'Second harbour']);
    dispatchDueStudioActions();

    runStudioActionJob($first->id);
    // Switched off in the database alone: the worker's settings are not told.
    $flags = json_decode((string) DB::table('project_settings')->where('id', 1)->value('feature_flags'), true);
    DB::table('project_settings')->where('id', 1)->update(['feature_flags' => json_encode([...$flags, 'content_studio_execution_enabled' => false])]);
    runStudioActionJob($second->id);

    expect($first->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and($second->fresh())->status->toBe(StudioActionStatus::Dispatched)->claimed_at->toBeNull()
        ->and(Post::query()->count())->toBe(1);
});

it('locks the action, then the users in ascending id order, then the plan, the photo and the post', function () {
    $admin = User::factory()->admin()->create();
    $action = approvedStudioAction([], $admin);
    dispatchDueStudioActions();
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (str_contains($sql, 'for update') && preg_match('/\\bfrom (studio_plan_actions|users|studio_plans|media_assets|posts)\\b/', $sql, $table) === 1) {
            $locked[] = $table[1].'#'.$query->bindings[0];
        }
    });

    app(ExecuteStudioAction::class)->handle($action->id);

    $persona = $action->actor_user_id;
    $plan = $action->studio_plan_id;
    $post = $action->fresh()->result_id;
    // The run, after the claim's two locks: the order every first lock is taken in.
    $firsts = array_values(array_unique(array_slice($locked, 2)));

    expect(array_slice($locked, 0, 2))->toBe(["studio_plan_actions#{$action->id}", "studio_plans#{$plan}"])
        ->and(array_slice($firsts, 0, 6))->toBe([
            "studio_plan_actions#{$action->id}",
            'users#'.min($persona, $admin->id),
            'users#'.max($persona, $admin->id),
            "studio_plans#{$plan}",
            'media_assets#'.StudioPlan::query()->findOrFail($plan)->image_asset_id,
            "posts#{$post}",
        ]);
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no order to observe.',
);

it('is reached only through its job, which carries the action\'s id and nothing else', function () {
    $action = approvedStudioAction();
    dispatchDueStudioActions();

    $payload = (string) DB::table('jobs')->value('payload');

    expect(array_map(fn (ExecuteStudioActionJob $job): int => $job->actionId, queuedJobs(ExecuteStudioActionJob::class)))->toBe([$action->id])
        ->and($payload)->not->toContain('App\\\\Models');
});
