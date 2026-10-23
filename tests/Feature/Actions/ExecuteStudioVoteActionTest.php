<?php

use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\LimitUserAction;
use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Studio\ExecuteStudioAction;
use App\Actions\Votes\VoteCommentAction;
use App\Actions\Votes\VotePostAction;
use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
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
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Ranking\HotScoreCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Running an approved plan's votes: each a real vote of its Persona — on the
 * post, on a comment or reply of its discussion, or for a rating option —
 * cast through the site's own actions once what it is on is published, dated
 * as the plan said however late it runs. Never cast twice, never toggling a
 * vote already there, never on anything but the plan's own post and comments;
 * what can no longer be voted on is cancelled with its reason, and nothing
 * already cast is taken back.
 */
beforeEach(function () {
    // The database queue, not a fake: a job dispatched after the commit is
    // queued only once the run commits, and never when it is rolled back.
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    switchStudioExecution(true);
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A plan with votes of every kind (approvableStudioPlanWithVotes()), approved
 * now — its post on that date when one is given — with its comments' actions
 * by key and its votes' actions by key.
 *
 * @return array{plan: StudioPlan, saved: mixed, votes: array<string, int>, voters: array{up: User, down: User}, group: mixed, options: list<mixed>, comments: array<string, StudioAction>, actions: array<string, StudioAction>}
 */
function approvedVotes(User $admin, ?string $publishAt = null): array
{
    $prepared = approvableStudioPlanWithVotes($publishAt !== null ? ['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse($publishAt)] : []);
    approveStudioPlan($admin, $prepared['plan']);

    return [
        ...$prepared,
        'comments' => studioActionsByNode($prepared['plan'], $prepared['saved']),
        'actions' => studioVoteActions($prepared['plan'], $prepared['votes']),
    ];
}

/** Rounds of the dispatcher and its worker until a round sends nothing. */
function runStudioRoundsUntilQuiet(): void
{
    foreach (range(1, 10) as $round) {
        if (runStudioRound() === 0) {
            return;
        }
    }
}

/**
 * @param  array<string, StudioAction>  $actions
 * @return array<string, StudioActionStatus>
 */
function voteStatuses(array $actions): array
{
    return array_map(fn (StudioAction $action): StudioActionStatus => $action->fresh()->status, $actions);
}

it('casts every vote of a historical plan as its Persona once what it is on is published, dated as planned, the post\'s and comments\' dates kept', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['plan' => $plan, 'actions' => $actions, 'comments' => $comments, 'voters' => $voters, 'options' => $options, 'group' => $group] = approvedVotes($this->admin, '2025-06-10 10:00:00');

    runStudioRoundsUntilQuiet();
    $post = Post::query()->sole();
    [$c1, $c2] = [Comment::query()->findOrFail($comments['c1']->fresh()->result_id), Comment::query()->findOrFail($comments['c2']->fresh()->result_id)];

    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed);

    // Each vote by its Persona, on what the plan said, dated as the plan said.
    foreach ([
        'postUp' => [PostVote::class, ['post_id' => $post->id, 'user_id' => $voters['up']->id, 'type' => VoteType::Up->value], '2025-06-10 10:01:00'],
        'postDown' => [PostVote::class, ['post_id' => $post->id, 'user_id' => $voters['down']->id, 'type' => VoteType::Down->value], '2025-06-10 10:02:00'],
        'ratingUp' => [RatingVote::class, ['post_id' => $post->id, 'user_id' => $voters['up']->id, 'rating_group_id' => $group->id, 'rating_option_id' => $options[0]->id], '2025-06-10 10:01:00'],
        'ratingDown' => [RatingVote::class, ['post_id' => $post->id, 'user_id' => $voters['down']->id, 'rating_group_id' => $group->id, 'rating_option_id' => $options[1]->id], '2025-06-10 10:03:00'],
        'c1Up' => [CommentVote::class, ['comment_id' => $c1->id, 'user_id' => $voters['up']->id, 'type' => VoteType::Up->value], '2025-06-10 10:10:00'],
        'c2Down' => [CommentVote::class, ['comment_id' => $c2->id, 'user_id' => $voters['down']->id, 'type' => VoteType::Down->value], '2025-06-10 10:15:00'],
    ] as $key => [$model, $where, $at]) {
        $vote = $model::query()->where($where)->sole();
        $action = $actions[$key]->fresh();

        expect($vote->created_at->toDateTimeString())->toBe($at, $key)
            ->and($vote->updated_at->toDateTimeString())->toBe($at, $key)
            ->and($action->result_type)->toBe((new $model)->getMorphClass())
            ->and($action->result_id)->toBe($vote->id)
            ->and($action->occurs_at->toDateTimeString())->toBe($at)
            // When it really ran.
            ->and($action->finished_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
            ->and($action->failure_code)->toBeNull();
    }

    // The counters and the rating results, and the post's and comments' own dates as the plan said.
    expect($post)->upvotes_count->toBe(1)->downvotes_count->toBe(1)
        ->and($post->created_at->toDateTimeString())->toBe('2025-06-10 10:00:00')
        ->and($post->updated_at->toDateTimeString())->toBe('2025-06-10 10:00:00')
        ->and($c1)->upvotes_count->toBe(1)->downvotes_count->toBe(0)
        ->and($c2)->upvotes_count->toBe(0)->downvotes_count->toBe(1)
        ->and($c1->updated_at->toDateTimeString())->toBe('2025-06-10 10:05:00')
        ->and($c2->updated_at->toDateTimeString())->toBe('2025-06-10 10:12:00')
        ->and(app(RatingVoteCountsQuery::class)->forPostsAndGroups([$post->id], [$group->id])[$post->id][$group->id]->mapWithKeys(fn ($row): array => [(int) $row->rating_option_id => (int) $row->aggregate])->all())
        ->toEqual([$options[0]->id => 1, $options[1]->id => 1])
        ->and(PostVote::query()->count() + CommentVote::query()->count() + RatingVote::query()->count())->toBe(6);
});

it('scores the post by the votes cast on it', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin);

    // The post now, its votes and comments when they come.
    Carbon::setTestNow('2026-10-20 10:31:00');
    runStudioRoundsUntilQuiet();
    $post = Post::query()->sole();

    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and($post->hot_score)->toBe(app(HotScoreCalculator::class)->calculate(1, 1, 6, $post->created_at, now()));
});

it('casts a future plan\'s votes no sooner than their own moments, each dated then', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin, '2026-11-01 08:30:00');

    runStudioRoundsUntilQuiet();
    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Pending]);

    // The post and its first vote due; the rest still to come.
    Carbon::setTestNow('2026-11-01 08:31:00');
    runStudioRoundsUntilQuiet();

    expect(voteStatuses($actions))->toMatchArray(['postUp' => StudioActionStatus::Done, 'ratingUp' => StudioActionStatus::Done, 'postDown' => StudioActionStatus::Pending, 'c1Up' => StudioActionStatus::Pending])
        ->and(PostVote::query()->sole()->created_at->toDateTimeString())->toBe('2026-11-01 08:31:00');

    Carbon::setTestNow('2026-11-01 09:00:00');
    runStudioRoundsUntilQuiet();

    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and(CommentVote::query()->orderBy('created_at')->pluck('created_at')->map(fn ($at): string => $at->toDateTimeString())->all())->toBe(['2026-11-01 08:40:00', '2026-11-01 08:45:00']);
});

it('chooses one option in each of several rating questions', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $plan = approvableStudioPlan();
    $voter = User::factory()->persona()->create();
    [, [$first]] = votableRatingQuestion();
    [, [, $second]] = votableRatingQuestion();
    $votes = saveStudioVotes($this->admin, $plan, [
        studioVote(['key' => 'a', 'type' => 'vote_rating', 'personaUserId' => $voter->id, 'ratingOptionId' => $first->id, 'offsetSeconds' => 60]),
        studioVote(['key' => 'b', 'type' => 'vote_rating', 'personaUserId' => $voter->id, 'ratingOptionId' => $second->id, 'offsetSeconds' => 120]),
    ])->ids;
    approveStudioPlan($this->admin, $plan);

    Carbon::setTestNow('2026-10-20 10:05:00');
    runStudioRoundsUntilQuiet();

    expect(array_unique(array_values(voteStatuses(studioVoteActions($plan, $votes))), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and(RatingVote::query()->where('user_id', $voter->id)->orderBy('rating_group_id')->pluck('rating_option_id')->all())->toBe([$first->id, $second->id]);
});

it('casts a vote once: its job delivered again, before or after it is done, casts and counts nothing more', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    runStudioRoundsUntilQuiet();
    $vote = PostVote::query()->where('type', VoteType::Up->value)->sole();

    // A second delivery of the done action's job, and a run asked of it again.
    runStudioActionJob($actions['postUp']->id);
    runStudioActionJob($actions['postUp']->id);

    expect(PostVote::query()->where('type', VoteType::Up->value)->sole()->is($vote))->toBeTrue()
        ->and(Post::query()->sole()->upvotes_count)->toBe(1)
        ->and($actions['postUp']->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(1);
});

it('finishes a vote already cast as planned without casting it again, the vote and its date its own', function (string $key, Closure $cast) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $approved = approvedVotes($this->admin, '2025-06-10 10:00:00');
    ['actions' => $actions] = $approved;
    // Everything up to the vote; then the Persona casts it itself, as planned.
    $actions[$key]->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    Carbon::setTestNow('2026-10-21 09:00:00');
    $mine = $cast($approved);
    $voterKey = RateLimitKey::userAction('vote', User::query()->findOrFail($actions[$key]->actor_user_id));
    $before = RateLimiter::attempts($voterKey);
    $actions[$key]->forceFill(['run_after' => now()])->save();

    runStudioRoundsUntilQuiet();

    expect($actions[$key]->fresh())->status->toBe(StudioActionStatus::Done)->result_type->toBe($mine->getMorphClass())->result_id->toBe($mine->id)
        ->and($mine->fresh()->created_at->toDateTimeString())->toBe('2026-10-21 09:00:00')
        // No action called: its limit not spent, and no second vote.
        ->and(RateLimiter::attempts($voterKey))->toBe($before)
        ->and($mine::query()->where('user_id', $mine->user_id)->count())->toBe(1)
        ->and($approved['plan']->fresh()->status)->toBe(StudioPlanStatus::Completed);
})->with([
    'on the post' => ['postUp', fn (array $approved) => PostVote::query()->create(['post_id' => Post::query()->sole()->id, 'user_id' => $approved['voters']['up']->id, 'type' => VoteType::Up])],
    'on a comment' => ['c1Up', fn (array $approved) => tap((new CommentVote)->forceFill(['comment_id' => $approved['comments']['c1']->fresh()->result_id, 'user_id' => $approved['voters']['up']->id, 'type' => VoteType::Up]))->save()],
    'for a rating option' => ['ratingUp', fn (array $approved) => tap((new RatingVote)->forceFill(['post_id' => Post::query()->sole()->id, 'user_id' => $approved['voters']['up']->id, 'rating_group_id' => $approved['group']->id, 'rating_option_id' => $approved['options'][0]->id]))->save()],
]);

it('fails, for an administrator, a vote whose Persona already voted the other way or chose another option — never taking it back or turning it', function (string $key, Closure $cast, Closure $kept) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $approved = approvedVotes($this->admin, '2025-06-10 10:00:00');
    ['actions' => $actions, 'plan' => $plan] = $approved;
    // Everything up to the vote, then the Persona's own vote the other way.
    $approved['actions'][$key]->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    $theirs = $cast($approved);
    $actions[$key]->forceFill(['run_after' => now()])->save();

    runStudioRoundsUntilQuiet();

    expect($actions[$key]->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('vote_conflict')->result_id->toBeNull()
        ->and($kept($theirs->fresh()))->toBeTrue()
        ->and($theirs::query()->whereKey($theirs->id)->exists())->toBeTrue()
        // Failed for an administrator: the plan waits for one.
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Running);
})->with([
    'up planned, down cast' => ['postUp', function (array $approved) {
        app(VotePostAction::class)->handle($approved['voters']['up'], Post::query()->sole(), VoteType::Down);

        return PostVote::query()->where('user_id', $approved['voters']['up']->id)->sole();
    }, fn (PostVote $vote): bool => $vote->type === VoteType::Down && Post::query()->sole()->downvotes_count === 2],
    'down planned on a reply, up cast' => ['c2Down', function (array $approved) {
        $comment = Comment::query()->findOrFail($approved['comments']['c2']->fresh()->result_id);
        app(VoteCommentAction::class)->handle($approved['voters']['down'], $comment, VoteType::Up);

        return CommentVote::query()->where('comment_id', $comment->id)->sole();
    }, fn (CommentVote $vote): bool => $vote->type === VoteType::Up && $vote->comment->upvotes_count === 1],
    'another option chosen' => ['ratingDown', function (array $approved) {
        app(VoteRatingOptionAction::class)->handle($approved['voters']['down'], Post::query()->sole(), $approved['options'][0]);

        return RatingVote::query()->where('user_id', $approved['voters']['down']->id)->sole();
    }, fn (RatingVote $vote): bool => $vote->rating_option_id === RatingVote::query()->where('user_id', $vote->user_id)->sole()->rating_option_id],
]);

it('sends a vote back to wait for its post or comment when its job comes first', function (string $key) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    // Sent by hand, its parent not done.
    $actions[$key]->forceFill(['status' => StudioActionStatus::Dispatched, 'attempts' => 1, 'dispatched_at' => now()])->save();

    runStudioActionJob($actions[$key]->id);

    expect($actions[$key]->fresh())->status->toBe(StudioActionStatus::Pending)->failure_code->toBe('parent_not_done')->deferrals->toBe(1)
        ->and(PostVote::query()->count() + CommentVote::query()->count() + RatingVote::query()->count())->toBe(0);
})->with(['postUp', 'ratingDown', 'c1Up']);

it('cancels the votes with the post or comment they are on, cancelled for good — the rest going on', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions, 'comments' => $comments, 'plan' => $plan] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    runStudioRoundsUntilQuiet();

    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done]);

    // Another plan whose post is cancelled before it runs: every vote of it goes with it.
    Carbon::setTestNow('2026-10-20 11:00:00');
    $other = approvedVotes($this->admin, '2025-06-10 10:00:00');
    app(BanUserAction::class)->handle($this->admin, User::query()->findOrFail($other['plan']->author_user_id), 'Spam');
    runStudioRoundsUntilQuiet();

    expect(array_unique(array_values(voteStatuses($other['actions'])), SORT_REGULAR))->toBe([StudioActionStatus::Cancelled])
        ->and(collect($other['actions'])->map(fn (StudioAction $action) => $action->fresh()->failure_code)->unique()->values()->all())->toBe(['parent_cancelled'])
        ->and($other['plan']->fresh()->status)->toBe(StudioPlanStatus::Cancelled)
        // Nothing of the first plan taken back.
        ->and(PostVote::query()->count())->toBe(2)
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Completed);
});

it('cancels a comment\'s votes with the comment and the replies under it, cancelled for good — the post\'s votes going on', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions, 'comments' => $comments] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    // The comments' Persona banned before they run: c1 is cancelled, its reply c2 with it, and the votes on both.
    app(BanUserAction::class)->handle($this->admin, User::query()->findOrFail($comments['c1']->actor_user_id), 'Spam');

    runStudioRoundsUntilQuiet();

    expect(voteStatuses($actions))->toMatchArray([
        'postUp' => StudioActionStatus::Done, 'postDown' => StudioActionStatus::Done,
        'ratingUp' => StudioActionStatus::Done, 'ratingDown' => StudioActionStatus::Done,
        'c1Up' => StudioActionStatus::Cancelled, 'c2Down' => StudioActionStatus::Cancelled,
    ])
        ->and($comments['c1']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('author_banned')
        ->and($actions['c1Up']->fresh()->failure_code)->toBe('parent_cancelled')
        ->and($actions['c2Down']->fresh()->failure_code)->toBe('parent_cancelled')
        ->and(CommentVote::query()->exists())->toBeFalse()
        ->and(PostVote::query()->count())->toBe(2);
});

it('cancels a vote on a post or comment hidden after approval, voting on nothing hidden — the other votes going on', function (string $hidden, array $cancelled, string $code) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $approved = approvedVotes($this->admin, '2025-06-10 10:00:00');
    ['actions' => $actions, 'comments' => $comments] = $approved;
    // Everything published, no vote cast yet.
    foreach ($actions as $action) {
        $action->forceFill(['run_after' => now()->addYear()])->save();
    }
    runStudioRoundsUntilQuiet();

    $hidden === 'post'
        ? app(HidePostAction::class)->handle($this->admin, Post::query()->sole(), 'Off-topic')
        : app(HideCommentAction::class)->handle($this->admin, Comment::query()->findOrFail($comments[$hidden]->fresh()->result_id), 'Off-topic');

    foreach ($actions as $action) {
        $action->fresh()->forceFill(['run_after' => now()])->save();
    }
    runStudioRoundsUntilQuiet();

    foreach ($actions as $key => $action) {
        $status = $action->fresh()->status;

        expect($status)->toBe(in_array($key, $cancelled, true) ? StudioActionStatus::Cancelled : StudioActionStatus::Done, $key);

        if (in_array($key, $cancelled, true)) {
            expect($action->fresh()->failure_code)->toBe($code);
        }
    }

    expect(PostVote::query()->count() + RatingVote::query()->count() + CommentVote::query()->count())->toBe(6 - count($cancelled));
})->with([
    'the post hidden: every vote on it, and on its comments' => ['post', ['postUp', 'postDown', 'ratingUp', 'ratingDown', 'c1Up', 'c2Down'], 'post_unavailable'],
    'a reply hidden: the vote on it alone' => ['c2', ['c2Down'], 'comment_unavailable'],
]);

it('holds a vote whose Persona was limited or shadowbanned, and cancels one banned, deleted or no longer a Persona — voting on nothing', function (Closure $change, StudioActionStatus $status, string $code) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $approved = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $change($approved['voters']['down'], $this->admin);

    runStudioRoundsUntilQuiet();

    foreach (['postDown', 'ratingDown', 'c2Down'] as $key) {
        expect($approved['actions'][$key]->fresh())->status->toBe($status)->failure_code->toBe($code);
    }

    expect(voteStatuses($approved['actions']))->toMatchArray(['postUp' => StudioActionStatus::Done, 'ratingUp' => StudioActionStatus::Done, 'c1Up' => StudioActionStatus::Done])
        ->and(PostVote::query()->where('user_id', $approved['voters']['down']->id)->exists())->toBeFalse()
        ->and(RatingVote::query()->where('user_id', $approved['voters']['down']->id)->exists())->toBeFalse()
        ->and(CommentVote::query()->where('user_id', $approved['voters']['down']->id)->exists())->toBeFalse();
})->with([
    'limited' => [fn (User $voter, User $admin) => app(LimitUserAction::class)->handle($admin, $voter, 'Spam'), StudioActionStatus::Held, 'author_restricted'],
    'shadowbanned' => [fn (User $voter) => $voter->forceFill(['status' => UserStatus::Shadowbanned])->save(), StudioActionStatus::Held, 'author_restricted'],
    'banned' => [fn (User $voter, User $admin) => app(BanUserAction::class)->handle($admin, $voter, 'Spam'), StudioActionStatus::Cancelled, 'author_banned'],
    'deleted' => [fn (User $voter) => $voter->forceFill(['status' => UserStatus::Deleted])->save(), StudioActionStatus::Cancelled, 'author_deleted'],
    'no longer a Persona' => [fn (User $voter) => $voter->forceFill(['role' => 'user'])->save(), StudioActionStatus::Cancelled, 'author_not_persona'],
]);

it('cancels a rating vote whose option, or its question, is no longer one to vote for — never choosing another in its place', function (Closure $change) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $approved = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $change($approved);

    runStudioRoundsUntilQuiet();

    expect($approved['actions']['ratingDown']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('rating_option_unavailable')
        ->and(RatingVote::query()->where('user_id', $approved['voters']['down']->id)->exists())->toBeFalse()
        ->and($approved['actions']['postDown']->fresh()->status)->toBe(StudioActionStatus::Done);
})->with([
    'the option archived' => [fn (array $approved) => $approved['options'][1]->forceFill(['is_active' => false, 'archived_at' => now()])->save()],
    'the option deleted' => [fn (array $approved) => $approved['options'][1]->delete()],
    'the option moved to another question' => [fn (array $approved) => $approved['options'][1]->forceFill(['rating_group_id' => votableRatingQuestion()[0]->id])->save()],
    'its question deactivated' => [function (array $approved) {
        $approved['group']->forceFill(['is_active' => false])->save();
        // The up vote's option is the same question's: cancelled too.
        $approved['actions']['ratingUp']->forceFill(['run_after' => now()->addYear()])->save();
    }],
]);

it('cancels a rating vote whose option is deleted after the run has looked at it and before the vote locks it — never failing it as unexpected', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions, 'voters' => $voters, 'options' => $options] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $actions['ratingDown']->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    $actions['ratingDown']->forceFill(['run_after' => now()])->save();
    dispatchDueStudioActions();
    $deleted = false;

    // The option deleted the moment the run has read it, unlocked: the vote's own lock then finds it gone.
    DB::listen(function (QueryExecuted $query) use ($options, &$deleted): void {
        if (! $deleted && preg_match('/from rating_options where rating_options\.id = \?/', strtolower(str_replace(['"', '`'], '', $query->sql))) === 1) {
            $deleted = true;
            DB::table('rating_options')->where('id', $options[1]->id)->delete();
        }
    });

    runStudioActionJob($actions['ratingDown']->id);

    expect($deleted)->toBeTrue()
        ->and($actions['ratingDown']->fresh())->status->toBe(StudioActionStatus::Cancelled)->failure_code->toBe('rating_option_unavailable')
        ->and(RatingVote::query()->where('user_id', $voters['down']->id)->exists())->toBeFalse()
        // The plan's other votes, cast before, untouched.
        ->and(voteStatuses($actions))->toMatchArray(['postUp' => StudioActionStatus::Done, 'postDown' => StudioActionStatus::Done, 'ratingUp' => StudioActionStatus::Done, 'c1Up' => StudioActionStatus::Done, 'c2Down' => StudioActionStatus::Done]);
});

it('never votes on another plan\'s post or comment, whatever the action it depends on says it wrote', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions, 'comments' => $comments] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $actions['c1Up']->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    $elsewhere = Comment::factory()->create(['post_id' => Post::factory()->published()->create()->id]);
    // Its comment's action now naming a comment of another post.
    $comments['c1']->fresh()->forceFill(['result_id' => $elsewhere->id])->save();
    $actions['c1Up']->forceFill(['run_after' => now()])->save();

    runStudioRoundsUntilQuiet();

    expect($actions['c1Up']->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('unexpected_failure')
        ->and(CommentVote::query()->where('comment_id', $elsewhere->id)->exists())->toBeFalse();
});

it('sends a vote back while its Persona is at its voting limit — never sooner, its date kept — casts it once the limit allows, and gives it up once deferred as often as it may', function () {
    config(['rate_limits.vote.max_attempts' => 1, 'rate_limits.vote.decay_seconds' => 60, 'content_studio.actions.max_deferrals' => 2]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions, 'voters' => $voters] = approvedVotes($this->admin, '2025-06-10 10:00:00');

    runStudioRoundsUntilQuiet();
    $waiting = collect($actions)->filter(fn (StudioAction $action): bool => $action->fresh()->status === StudioActionStatus::Pending);

    // One vote a minute for each voter: the rest wait, none given up, none cast twice.
    expect($waiting)->not->toBeEmpty()
        ->and($waiting->every(fn (StudioAction $action): bool => $action->fresh()->failure_code === 'rate_limited' && $action->fresh()->run_after->isFuture()))->toBeTrue()
        ->and($actions['ratingUp']->fresh()->occurs_at->toDateTimeString())->toBe('2025-06-10 10:01:00');

    foreach (range(1, 6) as $minute) {
        Carbon::setTestNow(now()->addSeconds(61));
        runStudioRoundsUntilQuiet();
    }

    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Done])
        ->and(PostVote::query()->count() + CommentVote::query()->count() + RatingVote::query()->count())->toBe(6)
        ->and(RatingVote::query()->where('user_id', $voters['up']->id)->sole()->created_at->toDateTimeString())->toBe('2025-06-10 10:01:00');

    // A limit that never lifts gives a vote up, failed and rate_limited, once deferred as often as it may.
    Carbon::setTestNow('2026-10-21 10:00:00');
    $other = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $other['actions']['postDown']->forceFill(['run_after' => now()->addYear()])->save();

    foreach (range(1, 5) as $round) {
        RateLimiter::hit(RateLimitKey::userAction('vote', $other['voters']['up']), 60);
        runStudioRoundsUntilQuiet();
        Carbon::setTestNow(now()->addSeconds(61));
    }

    expect($other['actions']['postUp']->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('rate_limited')->deferrals->toBe(2)
        ->and(PostVote::query()->where('user_id', $other['voters']['up']->id)->exists())->toBeFalse();
});

it('leaves a vote running for recovery when its run loses its connection, then casts it once', function () {
    config(['content_studio.actions.running_stale_after_seconds' => 180]);
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $actions['postUp']->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    $actions['postUp']->forceFill(['run_after' => now()])->save();
    dispatchDueStudioActions();
    PostVote::created(fn () => throw new QueryException('pgsql', 'insert into "post_votes"', [], new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly')));

    expect(fn () => runStudioActionJob($actions['postUp']->id))->toThrow(QueryException::class);
    expect($actions['postUp']->fresh())->status->toBe(StudioActionStatus::Running)->attempts->toBe(1)
        ->and(PostVote::query()->where('type', VoteType::Up->value)->exists())->toBeFalse()
        ->and(Post::query()->sole()->upvotes_count)->toBe(0);

    PostVote::flushEventListeners();
    Carbon::setTestNow('2026-10-20 10:04:00');
    dispatchDueStudioActions();
    runStudioActionJob($actions['postUp']->id);

    expect($actions['postUp']->fresh())->status->toBe(StudioActionStatus::Done)->attempts->toBe(2)
        ->and(PostVote::query()->where('type', VoteType::Up->value)->count())->toBe(1)
        ->and(Post::query()->sole()->upvotes_count)->toBe(1);
});

it('takes a whole vote back, counters too, when a write of its run fails — and fails the action', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $actions['c1Up']->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    $actions['c1Up']->forceFill(['run_after' => now()])->save();
    // The comment's counters not saved: the vote goes with them.
    Comment::updating(fn (Comment $comment): ?bool => $comment->isDirty('upvotes_count') ? throw new RuntimeException('Counters not saved.') : null);

    runStudioRoundsUntilQuiet();

    expect($actions['c1Up']->fresh())->status->toBe(StudioActionStatus::Failed)->failure_code->toBe('unexpected_failure')
        ->and(CommentVote::query()->where('type', VoteType::Up->value)->exists())->toBeFalse();
});

it('casts nothing while the executor is switched off', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    ['actions' => $actions] = approvedVotes($this->admin, '2025-06-10 10:00:00');
    runStudioRoundsUntilQuiet();
    switchStudioExecution(false);
    StudioAction::query()->whereKey(array_map(fn (StudioAction $action): int => $action->id, $actions))->update(['status' => StudioActionStatus::Dispatched->value, 'attempts' => 1, 'dispatched_at' => now()]);
    PostVote::query()->delete();
    CommentVote::query()->delete();
    RatingVote::query()->delete();

    expect(dispatchDueStudioActions())->toMatchArray(['enabled' => false]);

    foreach ($actions as $action) {
        runStudioActionJob($action->id);
    }

    expect(array_unique(array_values(voteStatuses($actions)), SORT_REGULAR))->toBe([StudioActionStatus::Dispatched])
        ->and(PostVote::query()->count() + CommentVote::query()->count() + RatingVote::query()->count())->toBe(0);
});

it('locks the action, then the users in ascending id order, then the plan, the post and the comment — and the vote last of all', function (string $key, Closure $users, array $after) {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $approved = approvedVotes($this->admin, '2025-06-10 10:00:00');
    $action = $approved['actions'][$key];
    $action->forceFill(['run_after' => now()->addYear()])->save();
    runStudioRoundsUntilQuiet();
    $action->forceFill(['run_after' => now()])->save();
    dispatchDueStudioActions();
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (preg_match('/for (no key )?update/', $sql) === 1 && preg_match('/\bfrom (studio_plan_actions|users|studio_plans|posts|comments|post_votes|comment_votes|rating_groups|rating_options)\b/', $sql, $table) === 1) {
            $locked[] = $table[1].(in_array($table[1], ['post_votes', 'comment_votes'], true) ? '' : '#'.$query->bindings[0]);
        }
    });

    app(ExecuteStudioAction::class)->handle($action->id);

    $post = Post::query()->sole();
    $comment = $approved['comments']['c1']->fresh()->result_id;
    // The run, after the claim's two locks: the order every first lock is taken in.
    $firsts = array_values(array_unique(array_slice($locked, 2)));

    expect($action->fresh()->status)->toBe(StudioActionStatus::Done)
        ->and(array_slice($locked, 0, 2))->toBe(["studio_plan_actions#{$action->id}", "studio_plans#{$approved['plan']->id}"])
        ->and($firsts)->toBe([
            "studio_plan_actions#{$action->id}",
            ...array_map(fn (int $id): string => "users#{$id}", $users($approved, $post)),
            "studio_plans#{$approved['plan']->id}",
            "posts#{$post->id}",
            ...array_map(fn (string $row): string => str_replace(['{comment}', '{group}'], [(string) $comment, (string) $approved['group']->id], $row), $after),
        ]);
})->with([
    // The post's author too: the vote's second update of the post checks its key.
    'a vote on the post' => ['postUp', fn (array $approved, Post $post): array => collect([$approved['voters']['up']->id, $post->user_id])->sort()->values()->all(), ['post_votes']],
    'a vote on a comment' => ['c1Up', fn (array $approved): array => [$approved['voters']['up']->id], ['comments#{comment}', 'comment_votes']],
    // The option is asked for within its question: the query's first binding is the question's.
    'a rating answer' => ['ratingUp', fn (array $approved): array => [$approved['voters']['up']->id], ['rating_groups#{group}', 'rating_options#{group}']],
])->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no order to observe.',
);
