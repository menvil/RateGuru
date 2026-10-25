<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Studio\ExecuteStudioAction;
use App\Enums\CommentStatus;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use App\Support\AbuseGuards\RateLimitKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Running an approved plan's discussion: each comment and reply a real one of
 * its Persona, under the post and the comment its plan said, dated as the
 * plan said however late it runs — never before what it depends on is done,
 * never twice, and nothing taken back that was published when one of them
 * cannot be.
 */
beforeEach(function () {
    // The database queue, not a fake: a job dispatched after the commit is
    // queued only once the run commits, and never when it is rolled back.
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
    $this->admin = User::factory()->admin()->create();
    $this->commenter = User::factory()->persona()->create();
    $this->replier = User::factory()->persona()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The plan with its discussion, approved now — a post on that date when one is
 * given — and its actions by what they publish.
 *
 * @return array<string, StudioAction>
 */
function approvedDiscussion($test, ?string $publishAt = null): array
{
    [$plan, $saved] = approvableStudioPlanWithDiscussion(
        $publishAt !== null ? ['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse($publishAt)] : [],
        $test->commenter,
        $test->replier,
    );
    approveStudioPlan($test->admin, $plan);

    return studioActionsByNode($plan, $saved);
}

/**
 * @param  array<string, StudioAction>  $actions
 * @return array<string, StudioActionStatus>
 */
function studioStatuses(array $actions): array
{
    return array_map(fn (StudioAction $action): StudioActionStatus => $action->fresh()->status, $actions);
}

/** The comment an action wrote. */
function writtenComment(StudioAction $action): Comment
{
    $action = $action->fresh();

    expect($action->result_type)->toBe(Comment::class);

    return Comment::withTrashed()->findOrFail($action->result_id);
}

it('publishes the post, then its comments, then their replies — each by its Persona, under its comment, dated as planned', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');

    runStudioRound();
    expect(studioStatuses($actions))->toMatchArray(['post' => StudioActionStatus::Done, 'c1' => StudioActionStatus::Pending, 'c2' => StudioActionStatus::Pending]);

    runStudioRound();
    expect(studioStatuses($actions))->toMatchArray(['c1' => StudioActionStatus::Done, 'c4' => StudioActionStatus::Done, 'c6' => StudioActionStatus::Done, 'c2' => StudioActionStatus::Pending, 'c5' => StudioActionStatus::Pending]);

    runStudioRound();
    $post = Post::query()->sole();

    expect(array_unique(array_values(studioStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and(Comment::query()->count())->toBe(6)
        ->and(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->toBe(StudioPlanStatus::Completed);

    foreach (['c1' => [null, 5], 'c2' => ['c1', 12], 'c3' => ['c1', 15], 'c4' => [null, 20], 'c5' => ['c4', 25], 'c6' => [null, 30]] as $key => [$parent, $minutes]) {
        $comment = writtenComment($actions[$key]);
        $at = CarbonImmutable::parse('2025-06-10 10:00:00')->addMinutes($minutes)->toDateTimeString();

        expect($comment)
            ->post_id->toBe($post->id)
            ->user_id->toBe($parent === null ? $this->commenter->id : $this->replier->id)
            ->parent_id->toBe($parent === null ? null : writtenComment($actions[$parent])->id)
            ->body->toBe($actions[$key]->payload['body'])
            ->status->toBe(CommentStatus::Visible)
            // The plan's date, not the run's: two years earlier.
            ->and($comment->created_at->toDateTimeString())->toBe($at)
            ->and($comment->updated_at->toDateTimeString())->toBe($at)
            ->and($actions[$key]->fresh()->finished_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
    }

    // Counted and scored, the post's own dates untouched by all of it.
    expect($post)
        ->comments_count->toBe(6)
        ->and($post->hot_score)->toBeGreaterThan(0)
        ->and($post->created_at->toDateTimeString())->toBe('2025-06-10 10:00:00')
        ->and($post->updated_at->toDateTimeString())->toBe('2025-06-10 10:00:00');
});

it('keeps a future post\'s discussion back until each comment\'s own moment, however soon its post was published', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2026-11-01 08:30:00');

    runStudioRound();
    expect(Post::query()->exists())->toBeFalse();

    Carbon::setTestNow('2026-11-01 08:30:00');
    runStudioRound();
    runStudioRound();
    expect(studioStatuses($actions))->toMatchArray(['post' => StudioActionStatus::Done, 'c1' => StudioActionStatus::Pending]);

    Carbon::setTestNow('2026-11-01 08:35:00');
    runStudioRound();
    runStudioRound();
    expect(studioStatuses($actions))->toMatchArray(['c1' => StudioActionStatus::Done, 'c2' => StudioActionStatus::Pending, 'c4' => StudioActionStatus::Pending]);

    // A worker an hour late: everything due runs, each still dated as planned.
    Carbon::setTestNow('2026-11-01 09:40:00');
    runStudioRound();
    runStudioRound();

    expect(array_unique(array_values(studioStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and(writtenComment($actions['c5'])->created_at->toDateTimeString())->toBe('2026-11-01 08:55:00');
});

it('waits for a parent held, failed or not yet run, cancelling none of its comments', function (Closure $stall) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    $stall($actions['post']);

    runStudioRound();
    runStudioRound();

    expect(Comment::query()->exists())->toBeFalse()
        ->and(array_unique(array_values(array_diff_key(studioStatuses($actions), ['post' => true])), SORT_REGULAR))->toBe([StudioActionStatus::Pending])
        ->and(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->not->toBe(StudioPlanStatus::Cancelled);

    // Once it is done, the rest follows.
    DB::table('studio_plan_actions')->where('id', $actions['post']->id)->update(['status' => 'pending', 'failure_code' => null, 'run_after' => now()]);
    User::query()->whereKey($actions['post']->actor_user_id)->update(['status' => UserStatus::Active]);
    runStudioRound();
    runStudioRound();
    runStudioRound();

    expect(Comment::query()->count())->toBe(6);
})->with([
    'held: its Persona limited' => [fn (StudioAction $post) => User::query()->whereKey($post->actor_user_id)->update(['status' => UserStatus::Limited])],
    'failed, for an administrator' => [fn (StudioAction $post) => DB::table('studio_plan_actions')->where('id', $post->id)->update(['status' => 'failed', 'failure_code' => 'unexpected_failure'])],
    'still due later' => [fn (StudioAction $post) => DB::table('studio_plan_actions')->where('id', $post->id)->update(['run_after' => '2026-10-20 11:00:00'])],
]);

it('cancels the whole discussion with the post cancelled for good, and the plan with it', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    app(BanUserAction::class)->handle($this->admin, User::query()->findOrFail($actions['post']->actor_user_id), 'Spam');

    runStudioRound();

    expect($actions['post']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('author_banned');

    foreach (array_diff_key($actions, ['post' => true]) as $action) {
        expect($action->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('parent_cancelled')->finished_at->not->toBeNull();
    }

    expect(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->toBe(StudioPlanStatus::Cancelled)
        ->and(Post::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(dispatchDueStudioActions())->toMatchArray(['sent' => 0, 'resent' => 0]);
});

it('cancels a reply whose comment was hidden or deleted since, and publishes everything else', function (Closure $remove) {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    runStudioRound();
    $remove(writtenComment($actions['c1']), $this);

    runStudioRound();

    expect($actions['c2']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('reply_target_unavailable')
        ->and($actions['c3']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('reply_target_unavailable')
        ->and($actions['c5']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(Comment::withTrashed()->where('parent_id', writtenComment($actions['c1'])->id)->exists())->toBeFalse()
        // Nothing left to run or decide: the post and the rest were published.
        ->and(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->toBe(StudioPlanStatus::Completed);
})->with([
    'hidden by a moderator' => [fn (Comment $comment, $test) => app(HideCommentAction::class)->handle($test->admin, $comment, 'Off topic')],
    'deleted by its author' => [fn (Comment $comment) => app(DeleteCommentAction::class)->handle(User::query()->findOrFail($comment->user_id), $comment)],
]);

it('cancels the comments of a post hidden or deleted since, and their replies with them', function (Closure $remove) {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    $remove(Post::query()->sole(), $this);

    runStudioRound();

    foreach (['c1', 'c4', 'c6'] as $key) {
        expect($actions[$key]->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('post_unavailable');
    }

    foreach (['c2', 'c3', 'c5'] as $key) {
        expect($actions[$key]->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('parent_cancelled');
    }

    expect(Comment::withTrashed()->exists())->toBeFalse()
        ->and($actions['post']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->toBe(StudioPlanStatus::Completed);
})->with([
    'hidden by a moderator' => [fn (Post $post, $test) => app(HidePostAction::class)->handle($test->admin, $post, 'Off topic')],
    'deleted' => [fn (Post $post) => $post->delete()],
]);

it('holds or cancels a comment as its Persona now stands, its branch with it, publishing the others', function (UserStatus|string $standing, StudioActionStatus $status, string $code, StudioActionStatus $replies) {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    // The commenter alone: the replier and the post's author are as they were.
    $this->commenter->forceFill($standing === 'member' ? ['role' => 'user'] : ['status' => $standing])->save();

    runStudioRound();
    runStudioRound();

    foreach (['c1', 'c4', 'c6'] as $key) {
        expect($actions[$key]->fresh())->status->toBe($status)->failure_code->toBe($code);
    }

    foreach (['c2', 'c3', 'c5'] as $key) {
        expect($actions[$key]->fresh()->status)->toBe($replies);
    }

    expect(Comment::query()->exists())->toBeFalse()
        ->and($actions['post']->fresh()->status)->toBe(StudioActionStatus::Done);
})->with([
    'limited: held for an administrator, its replies waiting' => [UserStatus::Limited, StudioActionStatus::Held, 'author_restricted', StudioActionStatus::Pending],
    'shadowbanned: held for an administrator, its replies waiting' => [UserStatus::Shadowbanned, StudioActionStatus::Held, 'author_restricted', StudioActionStatus::Pending],
    'banned: cancelled with its branch' => [UserStatus::Banned, StudioActionStatus::Cancelled, 'author_banned', StudioActionStatus::Cancelled],
    'deleted: cancelled with its branch' => [UserStatus::Deleted, StudioActionStatus::Cancelled, 'author_deleted', StudioActionStatus::Cancelled],
    'no longer a Persona: cancelled with its branch' => ['member', StudioActionStatus::Cancelled, 'author_not_persona', StudioActionStatus::Cancelled],
]);

it('publishes the other Personas\' comments on a post whose author was sanctioned after it was published', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    app(BanUserAction::class)->handle($this->admin, User::query()->findOrFail($actions['post']->actor_user_id), 'Spam');

    runStudioRound();
    runStudioRound();

    expect(Comment::query()->count())->toBe(6)
        ->and(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->toBe(StudioPlanStatus::Completed);
});

it('publishes the post\'s author\'s own comments under its post, telling it only of the others\'', function () {
    [$plan, $saved] = approvableStudioPlanWithDiscussion([
        'author_user_id' => $this->commenter->id,
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse('2025-06-10 10:00:00'),
    ], $this->commenter, $this->replier);
    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);

    runStudioRound();
    runStudioRound();
    runStudioRound();

    expect(Comment::query()->where('user_id', $this->commenter->id)->count())->toBe(3)
        ->and(Comment::query()->count())->toBe(6)
        ->and(writtenComment($actions['c1'])->post_id)->toBe($actions['post']->fresh()->result_id)
        // The replies by the other Persona, never its own comments.
        ->and($this->commenter->notifications()->where('type', PostCommentedNotification::class)->count())->toBe(3)
        ->and(StudioPlan::query()->findOrFail($plan->id)->status)->toBe(StudioPlanStatus::Completed);
});

it('tells the post\'s author of each comment once, with the real time, and nobody of a comment taken back', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    $author = User::query()->findOrFail($actions['post']->actor_user_id);
    runStudioRound();
    runStudioRound();
    runStudioRound();

    // Delivered again after it is done: nothing written, nothing told again.
    foreach ($actions as $action) {
        runStudioActionJob($action->id);
    }

    $told = $author->notifications()->where('type', PostCommentedNotification::class)->get();

    expect($told)->toHaveCount(6)
        ->and($told->pluck('data.comment_id')->sort()->values()->all())->toBe(Comment::query()->orderBy('id')->pluck('id')->all())
        ->and($told->first()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and(Comment::query()->count())->toBe(6)
        ->and(StudioAction::query()->where('attempts', '!=', 1)->exists())->toBeFalse();
});

it('takes a comment back whole when a write of its run fails, telling nobody, the action failed and the rest published', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    $post = Post::query()->sole();
    Comment::created(function (Comment $comment): void {
        if ($comment->body === 'Where is this?') {
            throw new RuntimeException('The comment could not be written.');
        }
    });

    runStudioRound();
    runStudioRound();

    expect($actions['c4']->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('unexpected_failure')->failure_reason->toBe(RuntimeException::class)
        ->and($actions['c5']->fresh()->status)->toBe(StudioActionStatus::Pending)
        ->and(Comment::query()->where('body', 'Where is this?')->exists())->toBeFalse()
        ->and($post->fresh()->comments_count)->toBe(4)
        ->and(User::query()->findOrFail($post->user_id)->notifications()->where('type', PostCommentedNotification::class)->count())->toBe(4)
        // A failure waits for an administrator, and keeps the plan Running.
        ->and(StudioPlan::query()->findOrFail($actions['post']->studio_plan_id)->status)->toBe(StudioPlanStatus::Running);
});

it('takes a comment back whole when a model event keeps it from being saved, never marking the action done without it', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    // Cancelled as a `creating` listener returning false cancels it: no exception, no row.
    Comment::creating(fn (Comment $comment): ?bool => $comment->body === 'Where is this?' ? false : null);

    runStudioRound();

    expect($actions['c4']->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('unexpected_failure')->result_id->toBeNull()
        ->and(Comment::query()->where('body', 'Where is this?')->exists())->toBeFalse()
        ->and(Post::query()->sole()->comments_count)->toBe(2);
});

it('leaves a comment running for recovery when its run loses its connection, then publishes it once', function () {
    config(['content_studio.actions.running_stale_after_seconds' => 180]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    dispatchDueStudioActions();
    $lose = Comment::created(fn () => throw new QueryException('pgsql', 'insert into "comments"', [], new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly')));

    expect(fn () => runStudioActionJob($actions['c1']->id))->toThrow(QueryException::class);
    expect($actions['c1']->fresh())->status->toBe(StudioActionStatus::Running)->attempts->toBe(1)
        ->and(Comment::query()->where('body', 'Lovely light on the water.')->exists())->toBeFalse();

    Comment::flushEventListeners();
    Carbon::setTestNow('2026-10-20 10:04:00');
    dispatchDueStudioActions();
    runStudioActionJob($actions['c1']->id);

    expect($actions['c1']->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(2)
        ->and(Comment::query()->where('body', 'Lovely light on the water.')->count())->toBe(1);
});

it('sends a comment back while its Persona is at its commenting limit — its date kept, never sooner — and publishes it once the limit allows', function () {
    config(['rate_limits.comment.max_attempts' => 2, 'rate_limits.comment.decay_seconds' => 60]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    runStudioRound();
    $third = $actions['c6']->fresh();

    expect(studioStatuses($actions))->toMatchArray(['c1' => StudioActionStatus::Done, 'c4' => StudioActionStatus::Done])
        ->and($third)->status->toBe(StudioActionStatus::Pending)->failure_code->toBe('rate_limited')->deferrals->toBe(1)->attempts->toBe(1)
        ->and($third->occurs_at->toDateTimeString())->toBe('2025-06-10 10:30:00')
        ->and($third->run_after->greaterThan(now()))->toBeTrue();

    // Not sent again before the limit allows.
    dispatchDueStudioActions();
    expect($actions['c6']->fresh()->status)->toBe(StudioActionStatus::Pending);

    Carbon::setTestNow($third->run_after->addSecond());
    runStudioRound();

    expect($actions['c6']->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(2)->failure_code->toBeNull()
        ->and(writtenComment($actions['c6'])->created_at->toDateTimeString())->toBe('2025-06-10 10:30:00');
});

it('gives a comment up, failed and rate_limited, once its Persona\'s commenting limit has sent it back as often as it may', function () {
    config(['rate_limits.comment.max_attempts' => 1, 'rate_limits.comment.decay_seconds' => 60, 'content_studio.actions.max_deferrals' => 1]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();

    // A limit that never lifts: the Persona's window is full before every run.
    foreach (range(1, 2) as $round) {
        RateLimiter::hit(RateLimitKey::userAction('comment', $this->commenter), 60);
        dispatchDueStudioActions();
        runStudioActionJob($actions['c1']->id);
        Carbon::setTestNow(now()->addSeconds(61));
    }

    expect($actions['c1']->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('rate_limited')->deferrals->toBe(1)
        ->and($actions['c1']->fresh()->finished_at)->not->toBeNull()
        ->and(Comment::query()->where('body', 'Lovely light on the water.')->exists())->toBeFalse()
        // Its replies wait for an administrator's decision, as it does.
        ->and($actions['c2']->fresh()->status)->toBe(StudioActionStatus::Pending);
});

it('publishes several comments of one moment, each keeping that moment', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $plan = approvableStudioPlan(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2025-06-10 10:00:00')]);
    saveStudioDiscussion($this->admin, $plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->commenter->id, 'body' => 'First.', 'offsetSeconds' => 600]),
        discussionNode(['key' => 'b', 'personaUserId' => $this->commenter->id, 'body' => 'Second.', 'sortOrder' => 1, 'offsetSeconds' => 600]),
        discussionNode(['key' => 'c', 'parentKey' => 'a', 'personaUserId' => $this->replier->id, 'body' => 'Third.', 'offsetSeconds' => 600]),
    ]);
    approveStudioPlan($this->admin, $plan);

    runStudioRound();
    runStudioRound();
    runStudioRound();

    expect(Comment::query()->pluck('created_at')->map(fn (CarbonImmutable|Carbon $at): string => $at->toDateTimeString())->unique()->values()->all())->toBe(['2025-06-10 10:10:00'])
        ->and(Comment::query()->count())->toBe(3);
});

it('claims no comment sent before its parent was done: it waits for it again, or goes with a parent cancelled', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    // Sent by hand, its post not run yet.
    $early = fn (StudioAction $action) => DB::table('studio_plan_actions')->where('id', $action->id)->update(['status' => 'dispatched', 'attempts' => 1, 'dispatched_at' => now()]);
    $early($actions['c1']);

    expect(app(ExecuteStudioAction::class)->handle($actions['c1']->id))->toBeNull()
        ->and($actions['c1']->fresh())->status->toBe(StudioActionStatus::Pending)->failure_code->toBe('parent_not_done')->deferrals->toBe(1)->claimed_at->toBeNull()
        ->and(Comment::query()->exists())->toBeFalse();

    DB::table('studio_plan_actions')->where('id', $actions['post']->id)->update(['status' => 'cancelled', 'failure_code' => 'author_banned']);
    $early($actions['c4']);
    app(ExecuteStudioAction::class)->handle($actions['c4']->id);

    expect($actions['c4']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('parent_cancelled')
        ->and($actions['c5']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('parent_cancelled')
        // Its sibling stays as it was, for its own parent to settle.
        ->and($actions['c1']->fresh()->status)->toBe(StudioActionStatus::Pending);
});

it('asks the parent again right before writing: a reply never written under a comment whose action is no longer done', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    runStudioRound();
    dispatchDueStudioActions();
    $locks = 0;

    // Between the reply's claim and its run — the second read of its row, the
    // first being the claim's — its comment's action is reopened by hand.
    DB::beforeExecuting(function (string $sql) use ($actions, &$locks): void {
        if (preg_match('/^\s*select\s+\*\s+from\s+["`]?studio_plan_actions["`]?\s+where/i', $sql) === 1 && ++$locks === 2) {
            DB::table('studio_plan_actions')->where('id', $actions['c1']->id)->update(['status' => 'failed']);
        }
    });

    runStudioActionJob($actions['c2']->id);

    expect($actions['c2']->fresh())->status->toBe(StudioActionStatus::Pending)->failure_code->toBe('parent_not_done')
        ->and(Comment::query()->where('body', 'Agreed, the mist helps.')->exists())->toBeFalse();
});

it('writes nothing for a comment\'s attempt recovery has already sent again', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    dispatchDueStudioActions();
    $locks = 0;

    DB::beforeExecuting(function (string $sql) use ($actions, &$locks): void {
        if (preg_match('/^\s*select\s+\*\s+from\s+["`]?studio_plan_actions["`]?\s+where/i', $sql) === 1 && ++$locks === 2) {
            DB::table('studio_plan_actions')->where('id', $actions['c1']->id)->update(['status' => 'dispatched', 'attempts' => 2, 'claimed_at' => null]);
        }
    });

    runStudioActionJob($actions['c1']->id);

    expect(Comment::query()->exists())->toBeFalse()
        ->and($actions['c1']->fresh())->status->toBe(StudioActionStatus::Dispatched)->attempts->toBe(2);
});

it('runs no comment while the executor is switched off', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    switchStudioExecution(false);

    runStudioRound();
    runStudioActionJob($actions['c1']->id);

    expect(Comment::query()->exists())->toBeFalse()
        ->and($actions['c1']->fresh()->status)->toBe(StudioActionStatus::Pending);
});

it('locks a reply\'s action, then the Persona and the post\'s author in ascending id order, then the plan, the post and the comment it answers', function () {
    $actions = approvedDiscussion($this, '2025-06-10 10:00:00');
    runStudioRound();
    runStudioRound();
    dispatchDueStudioActions();
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (preg_match('/for (no key )?update/', $sql) === 1 && preg_match('/\\bfrom (studio_plan_actions|users|studio_plans|posts|comments)\\b/', $sql, $table) === 1) {
            $locked[] = $table[1].'#'.$query->bindings[0];
        }
    });

    app(ExecuteStudioAction::class)->handle($actions['c2']->id);

    $author = $actions['post']->actor_user_id;
    $post = $actions['post']->fresh()->result_id;
    $plan = $actions['post']->studio_plan_id;
    // The run, after the claim's two locks: the order every first lock is taken in.
    $firsts = array_values(array_unique(array_slice($locked, 2)));

    expect(array_slice($firsts, 0, 6))->toBe([
        "studio_plan_actions#{$actions['c2']->id}",
        'users#'.min($this->replier->id, $author),
        'users#'.max($this->replier->id, $author),
        "studio_plans#{$plan}",
        "posts#{$post}",
        'comments#'.writtenComment($actions['c1'])->id,
    ])
        // Nothing locked a user the run had not locked before the post.
        ->and(array_values(array_filter(array_slice($locked, 2), fn (string $lock): bool => str_starts_with($lock, 'users#'))))
        ->each->toBeIn(['users#'.$this->replier->id, "users#{$author}"]);
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no order to observe.',
);

it('runs a plan without a discussion as before: its post alone, the plan completed', function () {
    $action = approvedStudioAction();

    runStudioRound();

    expect($action->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(StudioAction::query()->count())->toBe(1)
        ->and(StudioAction::query()->sole()->type)->toBe(StudioActionType::CreatePost)
        ->and(StudioPlan::query()->findOrFail($action->studio_plan_id)->status)->toBe(StudioPlanStatus::Completed);
});
