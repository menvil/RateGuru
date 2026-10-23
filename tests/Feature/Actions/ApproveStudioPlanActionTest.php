<?php

use App\Actions\Moderation\LimitUserAction;
use App\Actions\Studio\ApproveStudioPlanAction;
use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\RemoveStudioPlanImageAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanApprovalRejection;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Exceptions\Studio\CannotApproveStudioPlanException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * An administrator's approval of a complete Draft: one transaction marks the
 * plan Approved and writes the one Pending CreatePost that will publish it —
 * when the post happens and when it may run fixed from one reading of the
 * clock — and nothing is published, queued or sent. Anything that stops it
 * stops all of it, with one typed reason.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Whether the query reads `users` — the approval's first, its user locks.
 * Matched on the table rather than on FOR UPDATE, which SQLite leaves out.
 */
function approvalReadsUsers(string $sql): bool
{
    return preg_match('/^\s*select\b.*\bfrom\s+["`]?users["`]?\s/i', $sql) === 1;
}

/** The approval refused, and why. */
function approvalRefusal(Closure $approve): CannotApproveStudioPlanException
{
    try {
        $approve();
    } catch (CannotApproveStudioPlanException $refusal) {
        return $refusal;
    }

    throw new RuntimeException('The approval was not refused.');
}

it('approves a complete Draft at once: approved by the administrator, now, at a new version, with one pending post due now', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:15:30.654321', 'UTC'));
    $plan = approvableStudioPlan(['lock_version' => 3]);

    $approved = app(ApproveStudioPlanAction::class)->handle($this->admin, $plan, 3);
    $at = CarbonImmutable::parse('2026-10-20 10:15:30', 'UTC');

    expect($plan->fresh())
        ->status->toBe(StudioPlanStatus::Approved)
        ->approved_by_user_id->toBe($this->admin->id)
        ->lock_version->toBe(4)
        ->and($plan->fresh()->approved_at->equalTo($at))->toBeTrue()
        // The copy the caller holds, and the one returned, are the plan as it is now.
        ->and($plan)->status->toBe(StudioPlanStatus::Approved)->lock_version->toBe(4)
        ->and($approved)->toBe($plan);

    $action = StudioAction::query()->sole();

    expect($action)
        ->studio_plan_id->toBe($plan->id)
        ->parent_action_id->toBeNull()
        ->type->toBe(StudioActionType::CreatePost)
        ->actor_user_id->toBe($plan->author_user_id)
        ->payload->toBe([])
        ->status->toBe(StudioActionStatus::Pending)
        ->attempts->toBe(0)
        ->dispatched_at->toBeNull()
        ->claimed_at->toBeNull()
        ->finished_at->toBeNull()
        ->result_type->toBeNull()
        ->result_id->toBeNull()
        ->failure_code->toBeNull()
        // One reading of the clock, the moment of approval, for all three.
        ->and($action->occurs_at->equalTo($at))->toBeTrue()
        ->and($action->run_after->equalTo($at))->toBeTrue()
        ->and(DB::table('studio_plan_actions')->first(['occurs_at', 'run_after']))
        ->occurs_at->toStartWith('2026-10-20 10:15:30')
        ->run_after->toStartWith('2026-10-20 10:15:30');
});

it('keeps the date a plan is published on as the post\'s, letting a past one run from the approval and a future one from its date', function (string $publishAt, string $runAfter) {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:15:30', 'UTC'));
    $plan = approvableStudioPlan([
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse($publishAt, 'UTC'),
    ]);

    approveStudioPlan($this->admin, $plan);

    $action = StudioAction::query()->sole();

    expect($action->occurs_at->toDateTimeString())->toBe($publishAt)
        ->and($action->run_after->toDateTimeString())->toBe($runAfter)
        ->and($plan->fresh()->approved_at->toDateTimeString())->toBe('2026-10-20 10:15:30')
        // Stored in UTC, to the second, as given.
        ->and(DB::table('studio_plan_actions')->value('occurs_at'))->toStartWith($publishAt)
        ->and(DB::table('studio_plan_actions')->value('run_after'))->toStartWith($runAfter);
})->with([
    'a past date' => ['2021-01-01 12:00:00', '2026-10-20 10:15:30'],
    'a future date' => ['2026-11-01 08:30:00', '2026-11-01 08:30:00'],
    'the very moment of approval' => ['2026-10-20 10:15:30', '2026-10-20 10:15:30'],
]);

it('publishes nothing, queues nothing and notifies nobody', function () {
    Bus::fake();
    Notification::fake();
    $plan = approvableStudioPlan();

    approveStudioPlan($this->admin, $plan);

    expect(Post::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->sole()->status)->toBe(StudioActionStatus::Pending);
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('approves a plan once: a second approval writes no second action', function () {
    $plan = approvableStudioPlan();
    approveStudioPlan($this->admin, $plan);

    $again = approvalRefusal(fn () => app(ApproveStudioPlanAction::class)->handle($this->admin, $plan, $plan->lock_version));
    $byAnother = approvalRefusal(fn () => approveStudioPlan(User::factory()->admin()->create(), $plan));

    expect($again->rejection)->toBe(StudioPlanApprovalRejection::NotDraft)
        ->and($byAnother->rejection)->toBe(StudioPlanApprovalRejection::NotDraft)
        ->and(StudioAction::query()->count())->toBe(1)
        ->and($plan->fresh()->approved_by_user_id)->toBe($this->admin->id);
});

it('is an active administrator\'s alone, asked before and again on the locked rows', function (Closure $actor) {
    $plan = approvableStudioPlan();

    $refusal = approvalRefusal(fn () => approveStudioPlan($actor(), $plan));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::AccessDenied)
        ->and($plan->fresh())->status->toBe(StudioPlanStatus::Draft)->approved_at->toBeNull()
        ->and(StudioAction::query()->exists())->toBeFalse();
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create()],
]);

it('refuses an administrator whose access ends between the first check and the locked row', function () {
    $plan = approvableStudioPlan();
    $admin = $this->admin;
    $demoted = false;

    // The first user lock is about to be taken: the administrator is demoted just before it.
    DB::beforeExecuting(function (string $sql) use ($admin, &$demoted): void {
        if (! $demoted && approvalReadsUsers($sql)) {
            $demoted = true;
            DB::table('users')->where('id', $admin->id)->update(['role' => 'user']);
        }
    });

    $refusal = approvalRefusal(fn () => approveStudioPlan($admin, $plan));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::AccessDenied)
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('refuses a plan that is gone, past its Draft, or changed since it was seen', function (Closure $change, StudioPlanApprovalRejection $rejection) {
    $plan = approvableStudioPlan();
    $seen = $plan->lock_version;
    $change($plan, $this->admin);

    $refusal = approvalRefusal(fn () => app(ApproveStudioPlanAction::class)->handle($this->admin, $plan, $seen));

    expect($refusal->rejection)->toBe($rejection)
        ->and($refusal->problems)->toBe([])
        ->and(StudioAction::query()->exists())->toBeFalse();
})->with([
    'deleted' => [fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete(), StudioPlanApprovalRejection::PlanGone],
    'past its Draft' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Cancelled])->save(), StudioPlanApprovalRejection::NotDraft],
    'saved by another administrator' => [fn (StudioPlan $plan, User $admin) => app(UpdateStudioPlanAction::class)->handle($admin, $plan, new StudioPlanData(authorUserId: $plan->author_user_id, title: 'Retitled'), $plan->lock_version), StudioPlanApprovalRejection::StaleVersion],
    'its photo replaced' => [function (StudioPlan $plan, User $admin) {
        Storage::fake('public');
        app(SetStudioPlanImageAction::class)->handle($admin, $plan, UploadedFile::fake()->image('new.jpg', 64, 64), $plan->lock_version);
    }, StudioPlanApprovalRejection::StaleVersion],
]);

it('refuses a plan whose author another administrator changed after it was read, never locking a user after the plan', function () {
    $plan = approvableStudioPlan();
    $other = User::factory()->persona()->create();
    $changed = false;

    // Between the read of the author and the locks: the plan now names another Persona, at the same version.
    DB::beforeExecuting(function (string $sql) use ($plan, $other, &$changed): void {
        if (! $changed && approvalReadsUsers($sql)) {
            $changed = true;
            DB::table('studio_plans')->where('id', $plan->id)->update(['author_user_id' => $other->id]);
        }
    });

    $refusal = approvalRefusal(fn () => approveStudioPlan($this->admin, $plan));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::StaleVersion)
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('refuses a plan not ready to publish with every problem the preflight finds, writing nothing', function (Closure $arrange, StudioPlanApprovalRejection $rejection, array $problems) {
    $plan = approvableStudioPlan();
    $arrange($plan);
    $before = DB::table('studio_plans')->where('id', $plan->id)->first();

    $refusal = approvalRefusal(fn () => approveStudioPlan($this->admin, $plan));

    expect($refusal->rejection)->toBe($rejection)
        ->and($refusal->problems)->toBe($problems)
        ->and(DB::table('studio_plans')->where('id', $plan->id)->first())->toEqual($before)
        ->and(StudioAction::query()->exists())->toBeFalse();
})->with([
    'no title and no photo' => [fn (StudioPlan $plan) => $plan->forceFill(['title' => null, 'image_asset_id' => null])->save(), StudioPlanApprovalRejection::IncompletePlan, [StudioPlanApprovalProblem::TitleMissing, StudioPlanApprovalProblem::PhotoMissing]],
    'a limited author' => [fn (StudioPlan $plan) => $plan->author->forceFill(['status' => UserStatus::Limited])->save(), StudioPlanApprovalRejection::AuthorUnavailable, [StudioPlanApprovalProblem::AuthorRestricted]],
    'a deleted photo' => [fn (StudioPlan $plan) => $plan->imageAsset->delete(), StudioPlanApprovalRejection::InvalidMedia, [StudioPlanApprovalProblem::PhotoDeleted]],
    'an inactive category' => [fn (StudioPlan $plan) => $plan->forceFill(['category_id' => Category::factory()->inactive()->create()->id])->save(), StudioPlanApprovalRejection::InvalidTaxonomy, [StudioPlanApprovalProblem::CategoryInactive]],
    'an author\'s answer switched off' => [function (StudioPlan $plan) {
        $question = RatingGroup::factory()->create(['is_active' => true]);
        $answer = RatingOption::factory()->create(['rating_group_id' => $question->id, 'is_active' => false]);
        $plan->forceFill(['author_answer_option_ids' => [$answer->id]])->save();
    }, StudioPlanApprovalRejection::InvalidTaxonomy, [StudioPlanApprovalProblem::AnswerInactive]],
    'no date to publish on' => [fn (StudioPlan $plan) => $plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => null])->save(), StudioPlanApprovalRejection::InvalidPublicationDate, [StudioPlanApprovalProblem::PublishAtMissing]],
    // Every Persona of the discussion must be able to comment, as the post's author must be able to post.
    'a discussion by a limited Persona' => [fn (StudioPlan $plan) => StudioDiscussionNode::factory()->for($plan, 'plan')->create(['persona_user_id' => User::factory()->persona()->create(['status' => UserStatus::Limited])->id]), StudioPlanApprovalRejection::InvalidDiscussion, [StudioPlanApprovalProblem::DiscussionPersonaRestricted]],
]);

it('writes an action for every comment and reply of the discussion, under the post\'s, by its own Persona, with its text and comment kept', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:15:30', 'UTC'));
    $commenter = User::factory()->persona()->create();
    $replier = User::factory()->persona()->create();
    [$plan, $saved] = approvableStudioPlanWithDiscussion([], $commenter, $replier);

    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);

    expect(array_keys($actions))->toEqualCanonicalizing(['post', 'c1', 'c2', 'c3', 'c4', 'c5', 'c6'])
        ->and(StudioAction::query()->count())->toBe(7)
        ->and($actions['post'])->type->toBe(StudioActionType::CreatePost)->parent_action_id->toBeNull()->actor_user_id->toBe($plan->author_user_id);

    foreach (['c1' => 'post', 'c4' => 'post', 'c6' => 'post', 'c2' => 'c1', 'c3' => 'c1', 'c5' => 'c4'] as $key => $parent) {
        $node = StudioDiscussionNode::query()->findOrFail($saved->ids[$key]);

        expect($actions[$key])
            ->type->toBe($parent === 'post' ? StudioActionType::CreateComment : StudioActionType::CreateReply)
            ->parent_action_id->toBe($actions[$parent]->id)
            ->actor_user_id->toBe($parent === 'post' ? $commenter->id : $replier->id)
            ->payload->toBe(['discussion_node_id' => $node->id, 'body' => $node->body])
            ->status->toBe(StudioActionStatus::Pending)
            ->attempts->toBe(0)
            ->and($actions[$key]->occurs_at->toDateTimeString())->toBe(CarbonImmutable::parse('2026-10-20 10:15:30')->addSeconds($node->offset_seconds)->toDateTimeString())
            // A post published now: each comment may run at its own moment, after the approval.
            ->and($actions[$key]->run_after->equalTo($actions[$key]->occurs_at))->toBeTrue();
    }
});

it('dates each comment after its post, letting the past ones run from the approval and the future ones from their own moment', function (string $publishAt, array $runAfter) {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:15:30', 'UTC'));
    [$plan, $saved] = approvableStudioPlanWithDiscussion(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse($publishAt, 'UTC')]);

    approveStudioPlan($this->admin, $plan);
    $actions = studioActionsByNode($plan, $saved);

    expect($actions['c1']->occurs_at->toDateTimeString())->toBe(CarbonImmutable::parse($publishAt)->addMinutes(5)->toDateTimeString())
        ->and($actions['c2']->occurs_at->toDateTimeString())->toBe(CarbonImmutable::parse($publishAt)->addMinutes(12)->toDateTimeString())
        ->and($actions['c1']->run_after->toDateTimeString())->toBe($runAfter[0])
        ->and($actions['c2']->run_after->toDateTimeString())->toBe($runAfter[1]);
})->with([
    'a past post: everything may run at once, dated in the past' => ['2025-06-10 10:00:00', ['2026-10-20 10:15:30', '2026-10-20 10:15:30']],
    'a future post: each comment waits for its own moment' => ['2026-11-01 08:30:00', ['2026-11-01 08:35:00', '2026-11-01 08:42:00']],
    'a post ten minutes ago: what is past runs now, what is not waits' => ['2026-10-20 10:05:30', ['2026-10-20 10:15:30', '2026-10-20 10:17:30']],
]);

it('approves a discussion once: a second approval writes no action again', function () {
    [$plan] = approvableStudioPlanWithDiscussion();
    approveStudioPlan($this->admin, $plan);

    expect(approvalRefusal(fn () => approveStudioPlan(User::factory()->admin()->create(), $plan))->rejection)->toBe(StudioPlanApprovalRejection::NotDraft)
        ->and(StudioAction::query()->count())->toBe(7);
});

it('takes the whole approval back when any action of the discussion cannot be written', function () {
    [$plan] = approvableStudioPlanWithDiscussion();
    // The last reply's alone, as a model event cancels one: false, no exception.
    StudioAction::creating(fn (StudioAction $action): ?bool => $action->type === StudioActionType::CreateReply && $action->payload['body'] === 'Brittany, I think.' ? false : null);
    Log::spy();

    expect(fn () => approveStudioPlan($this->admin, $plan))->toThrow(RuntimeException::class, 'An action of the approved plan was not saved.');

    expect($plan->fresh())->status->toBe(StudioPlanStatus::Draft)->approved_at->toBeNull()->lock_version->toBe(2)
        ->and(StudioAction::query()->exists())->toBeFalse();
    Log::shouldNotHaveReceived('info', fn (string $message): bool => in_array($message, ['studio_plan.approved', 'studio.action.created'], true));
});

it('refuses a discussion whose Persona was sanctioned after the plan was seen, approving nothing', function () {
    $replier = User::factory()->persona()->create();
    [$plan] = approvableStudioPlanWithDiscussion([], null, $replier);
    $seen = $plan->lock_version;
    app(LimitUserAction::class)->handle(User::factory()->admin()->create(), $replier, 'Spam');

    $refusal = approvalRefusal(fn () => app(ApproveStudioPlanAction::class)->handle($this->admin, $plan, $seen));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::InvalidDiscussion)
        ->and($refusal->problems)->toBe([StudioPlanApprovalProblem::DiscussionPersonaRestricted])
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Draft)
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('refuses a discussion naming another Persona once the plan is locked than when it was read, never locking a user after the plan', function () {
    [$plan, $saved] = approvableStudioPlanWithDiscussion();
    $other = User::factory()->persona()->create();
    $changed = false;

    // Between the read of the Personas and the locks: a comment now names another, at the same version.
    DB::beforeExecuting(function (string $sql) use ($saved, $other, &$changed): void {
        if (! $changed && approvalReadsUsers($sql)) {
            $changed = true;
            DB::table('studio_plan_discussion_nodes')->where('id', $saved->ids['c6'])->update(['persona_user_id' => $other->id]);
        }
    });

    $refusal = approvalRefusal(fn () => approveStudioPlan($this->admin, $plan));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::StaleVersion)
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('writes an action for every prepared vote, by its Persona, under the action it depends on, keeping only what was approved', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:15:30', 'UTC'));
    ['plan' => $plan, 'saved' => $saved, 'votes' => $votes, 'voters' => $voters, 'group' => $group, 'options' => $options] = approvableStudioPlanWithVotes();

    approveStudioPlan($this->admin, $plan);
    $comments = studioActionsByNode($plan, $saved);
    $actions = studioVoteActions($plan, $votes);

    expect(StudioAction::query()->where('studio_plan_id', $plan->id)->count())->toBe(13)
        // Written after the discussion, by their moment, then their place, then the prepared vote's id.
        ->and(array_keys($actions))->toBe(['postUp', 'ratingUp', 'postDown', 'ratingDown', 'c1Up', 'c2Down'])
        ->and(min(array_map(fn (StudioAction $action): int => $action->id, $actions)))->toBeGreaterThan(max(array_map(fn (StudioAction $action): int => $action->id, $comments)));

    foreach ([
        'postUp' => [StudioActionType::VotePost, 'post', 'up', ['vote_type' => 'up'], 60],
        'postDown' => [StudioActionType::VotePost, 'post', 'down', ['vote_type' => 'down'], 120],
        'ratingUp' => [StudioActionType::VoteRating, 'post', 'up', ['rating_group_id' => $group->id, 'rating_option_id' => $options[0]->id], 60],
        'ratingDown' => [StudioActionType::VoteRating, 'post', 'down', ['rating_group_id' => $group->id, 'rating_option_id' => $options[1]->id], 180],
        'c1Up' => [StudioActionType::VoteComment, 'c1', 'up', ['vote_type' => 'up', 'discussion_node_id' => $saved->ids['c1']], 600],
        'c2Down' => [StudioActionType::VoteComment, 'c2', 'down', ['vote_type' => 'down', 'discussion_node_id' => $saved->ids['c2']], 900],
    ] as $key => [$type, $parent, $voter, $payload, $offset]) {
        expect($actions[$key])
            ->type->toBe($type)
            ->parent_action_id->toBe($comments[$parent]->id)
            ->actor_user_id->toBe($voters[$voter]->id)
            // No post, comment or user to act on: what the run votes on comes from the action it depends on.
            ->payload->toEqual([...$payload, 'studio_plan_vote_id' => $votes[$key]])
            ->status->toBe(StudioActionStatus::Pending)
            ->attempts->toBe(0)
            ->result_id->toBeNull()
            ->and($actions[$key]->occurs_at->toDateTimeString())->toBe(CarbonImmutable::parse('2026-10-20 10:15:30')->addSeconds($offset)->toDateTimeString())
            // A post published now: each vote may run at its own moment, after the approval.
            ->and($actions[$key]->run_after->equalTo($actions[$key]->occurs_at))->toBeTrue();
    }
});

it('dates each vote after its post, letting the past ones run from the approval and the future ones from their own moment', function (string $publishAt, array $runAfter) {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:15:30', 'UTC'));
    ['plan' => $plan, 'votes' => $votes] = approvableStudioPlanWithVotes(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse($publishAt, 'UTC')]);

    approveStudioPlan($this->admin, $plan);
    $actions = studioVoteActions($plan, $votes);

    expect($actions['postUp']->occurs_at->toDateTimeString())->toBe(CarbonImmutable::parse($publishAt)->addMinute()->toDateTimeString())
        ->and($actions['c2Down']->occurs_at->toDateTimeString())->toBe(CarbonImmutable::parse($publishAt)->addMinutes(15)->toDateTimeString())
        ->and($actions['postUp']->run_after->toDateTimeString())->toBe($runAfter[0])
        ->and($actions['c2Down']->run_after->toDateTimeString())->toBe($runAfter[1]);
})->with([
    'a past post: every vote may run at once, dated in the past' => ['2025-06-10 10:00:00', ['2026-10-20 10:15:30', '2026-10-20 10:15:30']],
    'a future post: each vote waits for its own moment' => ['2026-11-01 08:30:00', ['2026-11-01 08:31:00', '2026-11-01 08:45:00']],
    'a post ten minutes ago: what is past runs now, what is not waits' => ['2026-10-20 10:05:30', ['2026-10-20 10:15:30', '2026-10-20 10:20:30']],
]);

it('approves votes once: a second approval writes no action again', function () {
    ['plan' => $plan] = approvableStudioPlanWithVotes();
    approveStudioPlan($this->admin, $plan);

    expect(approvalRefusal(fn () => approveStudioPlan(User::factory()->admin()->create(), $plan))->rejection)->toBe(StudioPlanApprovalRejection::NotDraft)
        ->and(StudioAction::query()->count())->toBe(13);
});

it('refuses a plan whose votes cannot be cast as they are, with each problem as its own code, writing nothing', function (Closure $arrange, StudioPlanApprovalProblem $problem) {
    $prepared = approvableStudioPlanWithVotes();
    $arrange($prepared, $this->admin);
    $before = DB::table('studio_plans')->where('id', $prepared['plan']->id)->first();

    $refusal = approvalRefusal(fn () => approveStudioPlan($this->admin, $prepared['plan']));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::InvalidVotes)
        ->and($refusal->problems)->toBe([$problem])
        ->and(DB::table('studio_plans')->where('id', $prepared['plan']->id)->first())->toEqual($before)
        ->and(StudioAction::query()->exists())->toBeFalse();
})->with([
    'a voter limited since' => [fn (array $prepared, User $admin) => app(LimitUserAction::class)->handle($admin, $prepared['voters']['up'], 'Spam'), StudioPlanApprovalProblem::VotePersonaRestricted],
    'a voter deleted since' => [fn (array $prepared) => $prepared['voters']['down']->forceFill(['status' => UserStatus::Deleted])->save(), StudioPlanApprovalProblem::VotePersonaDeleted],
    'a voter no longer a persona' => [fn (array $prepared) => $prepared['voters']['up']->forceFill(['role' => 'user'])->save(), StudioPlanApprovalProblem::VotePersonaNotPersona],
    'the post given to a voter as its author' => [fn (array $prepared, User $admin) => app(UpdateStudioPlanAction::class)->handle(
        $admin, $prepared['plan'], new StudioPlanData(authorUserId: $prepared['voters']['up']->id, title: $prepared['plan']->title), $prepared['plan']->fresh()->lock_version,
    ), StudioPlanApprovalProblem::VoteOwnContent],
    'a comment written by its voter' => [fn (array $prepared) => DB::table('studio_plan_discussion_nodes')->where('id', $prepared['saved']->ids['c1'])->update(['persona_user_id' => $prepared['voters']['up']->id]), StudioPlanApprovalProblem::VoteOwnContent],
    'a vote before the comment it is on' => [fn (array $prepared) => DB::table('studio_plan_votes')->where('id', $prepared['votes']['c2Down'])->update(['offset_seconds' => 600]), StudioPlanApprovalProblem::VoteTimingInvalid],
    'an option archived since' => [fn (array $prepared) => $prepared['options'][0]->forceFill(['is_active' => false, 'archived_at' => now()])->save(), StudioPlanApprovalProblem::VoteOptionUnavailable],
    'its question no longer active' => [fn (array $prepared) => $prepared['group']->forceFill(['is_active' => false])->save(), StudioPlanApprovalProblem::VoteOptionUnavailable],
    'a vote past the dates a post may carry' => [function (array $prepared) {
        DB::table('studio_plans')->where('id', $prepared['plan']->id)->update(['publication_mode' => StudioPlanPublicationMode::AtDate->value, 'publish_at' => '2038-01-18 23:29:00']);
        DB::table('studio_plan_votes')->where('id', $prepared['votes']['postDown'])->update(['offset_seconds' => 3600]);
    }, StudioPlanApprovalProblem::VoteDateOutOfRange],
]);

it('takes the whole approval back when any action of the votes cannot be written', function () {
    ['plan' => $plan] = approvableStudioPlanWithVotes();
    // The last vote's alone, as a model event cancels one: false, no exception.
    StudioAction::creating(fn (StudioAction $action): ?bool => $action->type === StudioActionType::VoteComment && $action->payload['vote_type'] === 'down' ? false : null);
    Log::spy();

    expect(fn () => approveStudioPlan($this->admin, $plan))->toThrow(RuntimeException::class, 'An action of the approved plan was not saved.');

    expect($plan->fresh())->status->toBe(StudioPlanStatus::Draft)->approved_at->toBeNull()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Log::shouldNotHaveReceived('info', fn (string $message): bool => in_array($message, ['studio_plan.approved', 'studio.action.created'], true));
});

it('refuses votes naming another Persona once the plan is locked than when they were read, never locking a user after the plan', function () {
    ['plan' => $plan, 'votes' => $votes] = approvableStudioPlanWithVotes();
    $other = User::factory()->persona()->create();
    $changed = false;

    // Between the read of the Personas and the locks: a vote now names another, at the same version.
    DB::beforeExecuting(function (string $sql) use ($votes, $other, &$changed): void {
        if (! $changed && approvalReadsUsers($sql)) {
            $changed = true;
            DB::table('studio_plan_votes')->where('id', $votes['postDown'])->update(['persona_user_id' => $other->id]);
        }
    });

    $refusal = approvalRefusal(fn () => approveStudioPlan($this->admin, $plan));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::StaleVersion)
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('locks the administrator, the author, every Persona of the discussion and every voter in ascending id order, then the plan', function () {
    ['plan' => $plan, 'voters' => $voters] = approvableStudioPlanWithVotes();
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (str_contains($sql, 'for update') && preg_match('/\\bfrom (users|studio_plans)\\b/', $sql, $table) === 1) {
            $locked[] = $table[1].'#'.$query->bindings[0];
        }
    });

    approveStudioPlan($this->admin, $plan);

    $users = collect([$this->admin->id, $plan->author_user_id, ...StudioDiscussionNode::query()->where('studio_plan_id', $plan->id)->pluck('persona_user_id'), $voters['up']->id, $voters['down']->id])
        ->unique()->sort()->values()->map(fn (int $id): string => "users#{$id}")->all();

    expect(array_values(array_unique($locked)))->toBe([...$users, "studio_plans#{$plan->id}"]);
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no order to observe.',
);

it('refuses an author sanctioned after the plan was seen', function () {
    $plan = approvableStudioPlan();
    $seen = $plan->lock_version;
    app(LimitUserAction::class)->handle(User::factory()->admin()->create(), $plan->author, 'Spam');

    $refusal = approvalRefusal(fn () => app(ApproveStudioPlanAction::class)->handle($this->admin, $plan, $seen));

    expect($refusal->rejection)->toBe(StudioPlanApprovalRejection::AuthorUnavailable)
        ->and($refusal->problems)->toBe([StudioPlanApprovalProblem::AuthorRestricted])
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Draft);
});

it('takes both back when the action cannot be written: no approval without its action', function () {
    $plan = approvableStudioPlan();
    StudioAction::creating(fn () => throw new RuntimeException('The queue row could not be written.'));
    Log::spy();

    expect(fn () => approveStudioPlan($this->admin, $plan))->toThrow(RuntimeException::class);

    expect($plan->fresh())
        ->status->toBe(StudioPlanStatus::Draft)
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->lock_version->toBe(1)
        ->and(StudioAction::query()->exists())->toBeFalse();
    // Nothing committed, so nothing is logged as approved.
    Log::shouldNotHaveReceived('info', fn (string $message): bool => in_array($message, ['studio_plan.approved', 'studio.action.created'], true));
});

it('approves nothing when a model event cancels the plan\'s save, writing no action and keeping the version', function () {
    $plan = approvableStudioPlan();
    // Cancels the approval's save, as a `saving` listener returning false does: save() says false and throws nothing.
    StudioPlan::saving(fn (): bool => false);
    Log::spy();

    expect(fn () => approveStudioPlan($this->admin, $plan))
        ->toThrow(RuntimeException::class, 'The publication plan was not saved as approved.');

    expect(DB::table('studio_plans')->where('id', $plan->id)->first())
        ->status->toBe('draft')
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->and((int) DB::table('studio_plans')->where('id', $plan->id)->value('lock_version'))->toBe(1)
        ->and(StudioAction::query()->exists())->toBeFalse();
    Log::shouldNotHaveReceived('info', fn (string $message): bool => in_array($message, ['studio_plan.approved', 'studio.action.created'], true));
});

it('takes the approval back when a model event cancels the action\'s creation, leaving no action', function () {
    $plan = approvableStudioPlan();
    StudioAction::creating(fn (): bool => false);
    Log::spy();

    expect(fn () => approveStudioPlan($this->admin, $plan))
        ->toThrow(RuntimeException::class, 'An action of the approved plan was not saved.');

    expect($plan->fresh())
        ->status->toBe(StudioPlanStatus::Draft)
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->lock_version->toBe(1)
        ->and(StudioAction::query()->exists())->toBeFalse();
    Log::shouldNotHaveReceived('info', fn (string $message): bool => in_array($message, ['studio_plan.approved', 'studio.action.created'], true));
});

it('logs nothing of an approval an enclosing transaction rolls back', function () {
    $plan = approvableStudioPlan();
    Log::spy();

    DB::beginTransaction();
    approveStudioPlan($this->admin, $plan);
    DB::rollBack();

    expect($plan->fresh()->status)->toBe(StudioPlanStatus::Draft)
        ->and(StudioAction::query()->exists())->toBeFalse();
    Log::shouldNotHaveReceived('info', fn (string $message): bool => in_array($message, ['studio_plan.approved', 'studio.action.created'], true));
});

it('leaves an approved plan no longer edited, re-photographed or deleted as a Draft', function (Closure $write) {
    Storage::fake('public');
    $plan = approvableStudioPlan();
    approveStudioPlan($this->admin, $plan);

    expect(fn () => $write($this->admin, $plan))
        ->toThrow(fn (CannotManageStudioPlanException $refusal) => expect($refusal->isNotDraft())->toBeTrue());

    expect($plan->fresh())->status->toBe(StudioPlanStatus::Approved)
        ->and(StudioAction::query()->count())->toBe(1)
        ->and(MediaAsset::query()->whereKey($plan->image_asset_id)->exists())->toBeTrue();
})->with([
    'saved' => [fn (User $admin, StudioPlan $plan) => app(UpdateStudioPlanAction::class)->handle($admin, $plan, new StudioPlanData(title: 'Changed'), $plan->lock_version)],
    'given a new photo' => [fn (User $admin, StudioPlan $plan) => app(SetStudioPlanImageAction::class)->handle($admin, $plan, UploadedFile::fake()->image('new.jpg', 64, 64), $plan->lock_version)],
    'its photo taken off' => [fn (User $admin, StudioPlan $plan) => app(RemoveStudioPlanImageAction::class)->handle($admin, $plan, $plan->lock_version)],
    'deleted' => [fn (User $admin, StudioPlan $plan) => app(DeleteStudioPlanDraftAction::class)->handle($admin, $plan, $plan->lock_version)],
]);

it('logs the approval and its action with ids, the type, the state and the moments — never the texts', function () {
    Log::spy();
    $plan = approvableStudioPlan(['title' => 'A secret headline', 'description' => 'A secret description.']);

    approveStudioPlan($this->admin, $plan);
    $action = StudioAction::query()->sole();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio_plan.approved'
        && $context['studio_plan_id'] === $plan->id
        && $context['admin_id'] === $this->admin->id
        && $context['persona_id'] === $plan->author_user_id);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.action.created'
        && $context['studio_action_id'] === $action->id
        && $context['type'] === 'create_post'
        && $context['status'] === 'pending'
        && $context['run_after'] === $action->run_after->toIso8601String());
    Log::shouldNotHaveReceived('info', fn (string $message, array $context): bool => str_contains(json_encode($context), 'secret'));
});

it('logs a refusal with its code and its problems', function () {
    Log::spy();
    $plan = approvableStudioPlan(['title' => null]);

    approvalRefusal(fn () => approveStudioPlan($this->admin, $plan));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio_plan.approval_rejected'
        && $context['studio_plan_id'] === $plan->id
        && $context['admin_id'] === $this->admin->id
        && $context['persona_id'] === $plan->author_user_id
        && $context['code'] === 'incomplete_plan'
        && $context['problems'] === ['title_missing']);
    Log::shouldNotHaveReceived('info', fn (string $message): bool => $message === 'studio_plan.approved');
});
