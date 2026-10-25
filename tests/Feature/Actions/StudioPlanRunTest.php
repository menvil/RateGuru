<?php

use App\Actions\Studio\SwitchStudioExecutionAction;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Rating\RatingVoteCountsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * A whole plan, from its approval to its last vote, on the queue as it runs
 * in tests — every job handled the moment it is pushed — so nothing moves the
 * clock between one generation of actions and the next: approving a plan
 * sends its post at once, a post done sends its comments and votes already
 * due, a comment done sends its replies and votes, and an action still to
 * come waits for its own moment and the minute's run. Every date is the
 * plan's; when each ran is the action's own.
 */
beforeEach(function () {
    config(['queue.default' => 'sync']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A complete Draft dated 20 October 2026 at 12:00: comment `c1` an hour
 * after the post with reply `r1` a day after it; comment `c2` two days after
 * with reply `r2` three days after; votes — `ratingUp` a day after, `c1Up`
 * two days after, `postUp` and `r2Down` four days after, all past on 25
 * October — and `postDown` seven days after, on 27 October.
 *
 * @return array{plan: StudioPlan, nodes: array<string, int>, votes: array<string, int>, people: array<string, User>, options: list<mixed>, group: mixed}
 */
function historicalStudioPlan(array $attributes = []): array
{
    $plan = approvableStudioPlan(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-20 12:00:00'), ...$attributes]);
    $people = [
        'commenter' => User::factory()->persona()->create(),
        'replier' => User::factory()->persona()->create(),
        'voterA' => User::factory()->persona()->create(),
        'voterB' => User::factory()->persona()->create(),
    ];
    [$group, $options] = votableRatingQuestion();
    $admin = User::factory()->admin()->create();
    $hour = 3600;
    $day = 86400;

    $saved = saveStudioDiscussion($admin, $plan, [
        discussionNode(['key' => 'c1', 'personaUserId' => $people['commenter']->id, 'body' => 'Look at that light.', 'offsetSeconds' => $hour]),
        discussionNode(['key' => 'r1', 'parentKey' => 'c1', 'personaUserId' => $people['replier']->id, 'body' => 'It is the mist.', 'offsetSeconds' => $day]),
        discussionNode(['key' => 'c2', 'personaUserId' => $people['commenter']->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 2 * $day]),
        discussionNode(['key' => 'r2', 'parentKey' => 'c2', 'personaUserId' => $people['replier']->id, 'body' => 'Brittany.', 'offsetSeconds' => 3 * $day]),
    ]);

    $votes = saveStudioVotes($admin, $plan->fresh(), [
        studioVote(['key' => 'ratingUp', 'type' => 'vote_rating', 'personaUserId' => $people['voterA']->id, 'ratingOptionId' => $options[0]->id, 'offsetSeconds' => $day]),
        studioVote(['key' => 'c1Up', 'type' => 'vote_comment', 'personaUserId' => $people['voterB']->id, 'voteType' => 'up', 'discussionNodeId' => $saved->ids['c1'], 'offsetSeconds' => 2 * $day]),
        studioVote(['key' => 'postUp', 'type' => 'vote_post', 'personaUserId' => $people['voterA']->id, 'voteType' => 'up', 'offsetSeconds' => 4 * $day]),
        studioVote(['key' => 'r2Down', 'type' => 'vote_comment', 'personaUserId' => $people['voterA']->id, 'voteType' => 'down', 'discussionNodeId' => $saved->ids['r2'], 'offsetSeconds' => 4 * $day, 'sortOrder' => 1]),
        studioVote(['key' => 'postDown', 'type' => 'vote_post', 'personaUserId' => $people['voterB']->id, 'voteType' => 'down', 'offsetSeconds' => 7 * $day]),
    ]);

    return ['plan' => $plan->fresh(), 'nodes' => $saved->ids, 'votes' => $votes->ids, 'people' => $people, 'options' => $options, 'group' => $group];
}

/**
 * A plan's actions by what they write: `post`, each comment by its key and
 * each vote by its key.
 *
 * @param  array<string, int>  $nodes
 * @param  array<string, int>  $votes
 * @return array<string, StudioAction>
 */
function runActionsByKey(StudioPlan $plan, array $nodes, array $votes): array
{
    $byNode = array_flip($nodes);
    $byVote = array_flip($votes);
    $actions = [];

    foreach (StudioAction::query()->where('studio_plan_id', $plan->id)->orderBy('id')->get() as $action) {
        $key = match (true) {
            $action->type === StudioActionType::CreatePost => 'post',
            $action->type->isVote() => $byVote[$action->payload['studio_plan_vote_id']],
            default => $byNode[$action->payload['discussion_node_id']],
        };
        $actions[$key] = $action;
    }

    return $actions;
}

it('publishes a historical plan the moment it is approved — its post, then every comment, reply and vote already due, generation after generation without the clock moving — and leaves the one still to come for its own moment', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    ['plan' => $plan, 'nodes' => $nodes, 'votes' => $votes, 'people' => $people, 'options' => $options, 'group' => $group] = historicalStudioPlan();

    approveStudioPlan($this->admin, $plan);
    $actions = runActionsByKey($plan, $nodes, $votes);

    // Every action written once, and all but the one still to come done, now.
    expect($actions)->toHaveCount(10)
        ->and(StudioAction::query()->where('studio_plan_id', $plan->id)->count())->toBe(10)
        ->and(collect($actions)->except('postDown')->map(fn (StudioAction $action): StudioActionStatus => $action->fresh()->status)->unique()->values()->all())->toBe([StudioActionStatus::Done])
        ->and(collect($actions)->except('postDown')->every(fn (StudioAction $action): bool => $action->fresh()->finished_at->toDateTimeString() === '2026-10-25 12:00:00' && $action->fresh()->attempts === 1))->toBeTrue()
        ->and($actions['postDown']->fresh())->status->toBe(StudioActionStatus::Pending)->attempts->toBe(0)
        ->and($actions['postDown']->fresh()->run_after->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Running);

    // Each record dated as the plan said, however late it ran.
    $post = Post::query()->sole();
    $comment = fn (string $key): Comment => Comment::query()->findOrFail($actions[$key]->fresh()->result_id);

    expect($post->created_at->toDateTimeString())->toBe('2026-10-20 12:00:00')
        ->and($post->updated_at->toDateTimeString())->toBe('2026-10-20 12:00:00')
        ->and($post->published_at->toDateTimeString())->toBe('2026-10-20 12:00:00')
        ->and($comment('c1')->created_at->toDateTimeString())->toBe('2026-10-20 13:00:00')
        ->and($comment('r1')->created_at->toDateTimeString())->toBe('2026-10-21 12:00:00')
        ->and($comment('r1')->parent_id)->toBe($comment('c1')->id)
        ->and($comment('c2')->created_at->toDateTimeString())->toBe('2026-10-22 12:00:00')
        ->and($comment('r2')->created_at->toDateTimeString())->toBe('2026-10-23 12:00:00')
        ->and(PostVote::query()->where(['post_id' => $post->id, 'user_id' => $people['voterA']->id, 'type' => VoteType::Up->value])->sole()->created_at->toDateTimeString())->toBe('2026-10-24 12:00:00')
        ->and(CommentVote::query()->where(['comment_id' => $comment('c1')->id, 'user_id' => $people['voterB']->id])->sole()->created_at->toDateTimeString())->toBe('2026-10-22 12:00:00')
        ->and(CommentVote::query()->where(['comment_id' => $comment('r2')->id, 'user_id' => $people['voterA']->id, 'type' => VoteType::Down->value])->sole()->created_at->toDateTimeString())->toBe('2026-10-24 12:00:00')
        ->and(RatingVote::query()->where(['post_id' => $post->id, 'user_id' => $people['voterA']->id])->sole()->created_at->toDateTimeString())->toBe('2026-10-21 12:00:00');

    // The counters and the rating results.
    expect($post->fresh())->upvotes_count->toBe(1)->downvotes_count->toBe(0)->comments_count->toBe(4)
        ->and($comment('c1')->fresh())->upvotes_count->toBe(1)
        ->and($comment('r2')->fresh())->downvotes_count->toBe(1)
        ->and(app(RatingVoteCountsQuery::class)->forPostsAndGroups([$post->id], [$group->id])[$post->id][$group->id]->mapWithKeys(fn ($row): array => [(int) $row->rating_option_id => (int) $row->aggregate])->all())
        ->toEqual([$options[0]->id => 1]);

    // A job delivered again, and the dispatcher's own minute, change nothing.
    runStudioActionJob($actions['post']->id);
    runStudioActionJob($actions['c1Up']->id);
    dispatchDueStudioActions();

    expect(Post::query()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(4)
        ->and(PostVote::query()->count() + CommentVote::query()->count() + RatingVote::query()->count())->toBe(4)
        ->and($actions['post']->fresh()->attempts)->toBe(1);

    // The one still to come runs at its moment, dated then, and the plan is complete.
    Carbon::setTestNow('2026-10-27 12:00:30');
    dispatchDueStudioActions();

    expect($actions['postDown']->fresh())->status->toBe(StudioActionStatus::Done)
        ->and(PostVote::query()->where(['post_id' => $post->id, 'user_id' => $people['voterB']->id, 'type' => VoteType::Down->value])->sole()->created_at->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and($post->fresh()->downvotes_count)->toBe(1)
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed);
});

it('publishes a plan published at once the moment it is approved, and its comments when they come', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion();

    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);

    expect($actions['post']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(Post::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-25 12:00:00')
        ->and(collect($actions)->except('post')->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Pending))->toBeTrue();

    // Twelve minutes on: c1 at 5 and its reply c2 at 12, one minute's run.
    Carbon::setTestNow('2026-10-25 12:12:00');
    dispatchDueStudioActions();

    expect($actions['c1']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and($actions['c2']->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and($actions['c3']->fresh()->status)->toBe(StudioActionStatus::Pending)
        ->and(Comment::query()->findOrFail($actions['c2']->fresh()->result_id)->created_at->toDateTimeString())->toBe('2026-10-25 12:12:00');
});

it('runs nothing of a future plan before its date, and then its post and everything due after it in the one run that finds it due', function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-27 09:00:00')]);

    approveStudioPlan($this->admin, $plan);
    dispatchDueStudioActions();
    $actions = studioActionsByNode($plan, $saved);

    expect(collect($actions)->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Pending))->toBeTrue()
        ->and(Post::query()->count())->toBe(0);

    // Half an hour past the date: the post, and every comment and reply by then, in one minute's run.
    Carbon::setTestNow('2026-10-27 09:31:00');
    dispatchDueStudioActions();

    expect(collect($actions)->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Done))->toBeTrue()
        ->and(Post::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-27 09:00:00')
        ->and(Comment::query()->findOrFail($actions['c6']->fresh()->result_id)->created_at->toDateTimeString())->toBe('2026-10-27 09:30:00')
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed);
});

it('carries a historical plan larger than one batch through to its end, each run that sends a whole batch waking the next', function () {
    config(['content_studio.actions.batch_size' => 2]);
    Carbon::setTestNow('2026-10-25 12:00:00');
    [$plan, $saved] = approvableStudioPlanWithDiscussion(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2026-10-20 12:00:00')]);

    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);

    expect($actions)->toHaveCount(7)
        ->and(collect($actions)->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Done && $action->fresh()->attempts === 1))->toBeTrue()
        ->and(Comment::query()->count())->toBe(6)
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed);
});

it('waits with the executor off, and runs everything due the moment an administrator switches it on', function () {
    switchStudioExecution(false);
    Carbon::setTestNow('2026-10-25 12:00:00');
    ['plan' => $plan, 'nodes' => $nodes, 'votes' => $votes] = historicalStudioPlan();

    approveStudioPlan($this->admin, $plan);
    $actions = runActionsByKey($plan, $nodes, $votes);

    expect(collect($actions)->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Pending))->toBeTrue()
        ->and(Post::query()->count())->toBe(0);

    app(SwitchStudioExecutionAction::class)->handle($this->admin, true);

    expect(collect($actions)->except('postDown')->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Done))->toBeTrue()
        ->and($actions['postDown']->fresh()->status)->toBe(StudioActionStatus::Pending)
        ->and(Post::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-20 12:00:00');
});

it('holds a persona\'s votes past its voting limit back — none lost, none cast before the limit allows — and casts them once it does', function () {
    config(['rate_limits.vote.max_attempts' => 1, 'rate_limits.vote.decay_seconds' => 60]);
    Carbon::setTestNow('2026-10-25 12:00:00');
    ['plan' => $plan, 'nodes' => $nodes, 'votes' => $votes] = historicalStudioPlan();

    approveStudioPlan($this->admin, $plan);
    $actions = runActionsByKey($plan, $nodes, $votes);

    // Voter A has three votes due and one vote a minute: one cast, two sent back for a minute on.
    $voterA = collect(['ratingUp', 'postUp', 'r2Down'])->map(fn (string $key): StudioAction => $actions[$key]->fresh());

    expect($voterA->filter(fn (StudioAction $action): bool => $action->status === StudioActionStatus::Done))->toHaveCount(1)
        ->and($voterA->filter(fn (StudioAction $action): bool => $action->status === StudioActionStatus::Pending && $action->failure_code === 'rate_limited' && $action->run_after->isFuture()))->toHaveCount(2)
        ->and($actions['c1Up']->fresh()->status)->toBe(StudioActionStatus::Done);

    // Not before the limit allows: the minute's run at once sends nothing more.
    dispatchDueStudioActions();
    expect($voterA->map(fn (StudioAction $action): StudioActionStatus => $action->fresh()->status)->filter(fn (StudioActionStatus $status): bool => $status === StudioActionStatus::Done))->toHaveCount(1);

    // Two minutes on, a run at each: both cast, each dated as planned.
    foreach (['2026-10-25 12:01:01', '2026-10-25 12:02:02', '2026-10-25 12:03:03'] as $at) {
        Carbon::setTestNow($at);
        dispatchDueStudioActions();
    }

    expect($voterA->every(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Done))->toBeTrue()
        ->and(RatingVote::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-21 12:00:00');
});
