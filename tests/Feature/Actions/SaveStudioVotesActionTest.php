<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\SaveStudioVotesAction;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\CommentVote;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Saving every vote a Draft prepares: written at once — added, changed and
 * deleted together, or not at all — each keeping its id, under the plan's
 * lock and version, and nothing cast.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    [$this->author, $this->anna, $this->boris] = User::factory()->persona()->count(3)->create()->all();
    $this->plan = StudioPlan::factory()->authoredBy($this->author)->create(['created_by_user_id' => $this->admin->id]);
    $this->comment = StudioDiscussionNode::factory()->for($this->plan, 'plan')->create(['persona_user_id' => $this->anna->id, 'offset_seconds' => 300]);
    $this->reply = StudioDiscussionNode::factory()->replyTo($this->comment)->create(['persona_user_id' => $this->boris->id, 'offset_seconds' => 600]);
    [$this->group, [$this->option, $this->other]] = votableRatingQuestion();
});

/** The votes as stored, in the order they are cast: who, what, which way, when. */
function storedStudioVotes(StudioPlan $plan): array
{
    return $plan->votes()->get()
        ->map(fn (StudioPlanVote $vote): array => [
            'persona' => $vote->persona_user_id,
            'type' => $vote->type->value,
            'vote' => $vote->vote_type?->value,
            'node' => $vote->discussion_node_id,
            'group' => $vote->rating_group_id,
            'option' => $vote->rating_option_id,
            'target' => $vote->target_key,
            'offset' => $vote->offset_seconds,
            'sort' => $vote->sort_order,
        ])
        ->all();
}

/** The save refused, and why. */
function votesRefusal(Closure $save): Throwable
{
    try {
        $save();
    } catch (CannotManageStudioPlanException|ValidationException $refusal) {
        return $refusal;
    }

    throw new RuntimeException('The save was not refused.');
}

/** The plan's votes as stored, each with every field the editor would hand back. */
function editedStudioVotes(StudioPlan $plan, Closure $edit): array
{
    return array_map($edit, StudioVotesData::of(StudioPlan::query()->findOrFail($plan->id))->votes);
}

it('saves no votes, raising the version and writing nothing else', function () {
    $saved = saveStudioVotes($this->admin, $this->plan, []);

    expect($saved->lockVersion)->toBe(2)
        ->and($saved->votes)->toHaveCount(0)
        ->and($this->plan->lock_version)->toBe(2)
        ->and(StudioPlanVote::query()->exists())->toBeFalse();
});

it('saves every kind of vote, each new one named against its key, in the order they are cast', function () {
    $saved = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['key' => 'rating', 'type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->other->id, 'offsetSeconds' => 120]),
        studioVote(['key' => 'post', 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60, 'sortOrder' => 1]),
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'down', 'offsetSeconds' => 60]),
        studioVote(['key' => 'reply', 'type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 900]),
        studioVote(['key' => 'comment', 'type' => 'vote_comment', 'personaUserId' => $this->boris->id, 'voteType' => 'down', 'discussionNodeId' => $this->comment->id, 'offsetSeconds' => 300]),
    ]);

    expect(storedStudioVotes($this->plan))->toBe([
        ['persona' => $this->boris->id, 'type' => 'vote_post', 'vote' => 'down', 'node' => null, 'group' => null, 'option' => null, 'target' => 'post', 'offset' => 60, 'sort' => 0],
        ['persona' => $this->anna->id, 'type' => 'vote_post', 'vote' => 'up', 'node' => null, 'group' => null, 'option' => null, 'target' => 'post', 'offset' => 60, 'sort' => 1],
        // Its question is its option's own, though the editor named none.
        ['persona' => $this->anna->id, 'type' => 'vote_rating', 'vote' => null, 'node' => null, 'group' => $this->group->id, 'option' => $this->other->id, 'target' => "rating:{$this->group->id}", 'offset' => 120, 'sort' => 0],
        ['persona' => $this->boris->id, 'type' => 'vote_comment', 'vote' => 'down', 'node' => $this->comment->id, 'group' => null, 'option' => null, 'target' => "comment:{$this->comment->id}", 'offset' => 300, 'sort' => 0],
        ['persona' => $this->author->id, 'type' => 'vote_comment', 'vote' => 'up', 'node' => $this->reply->id, 'group' => null, 'option' => null, 'target' => "comment:{$this->reply->id}", 'offset' => 900, 'sort' => 0],
    ])
        ->and(array_keys($saved->ids))->toBe(['rating', 'post', 'reply', 'comment'])
        ->and($saved->votes->pluck('id')->all())->toBe(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->orderBy('offset_seconds')->orderBy('sort_order')->orderBy('id')->pluck('id')->all())
        ->and($saved->ids['rating'])->toBeLessThan($saved->ids['post'])
        ->and($saved->lockVersion)->toBe(2);
});

it('keeps every vote\'s id through an edit — its way, its moment, its place, its option — and gives new ones theirs', function () {
    $first = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['key' => 'post', 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['key' => 'rating', 'type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 120]),
    ]);
    [$post, $rating] = [$first->ids['post'], $first->ids['rating']];
    $created = StudioPlanVote::query()->findOrFail($post)->created_at;

    $second = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['id' => $post, 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'down', 'offsetSeconds' => 90, 'sortOrder' => 3]),
        studioVote(['id' => $rating, 'type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->other->id, 'offsetSeconds' => 120]),
        studioVote(['key' => 'new', 'type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 30]),
    ]);

    expect($second->votes->pluck('id')->all())->toBe([$second->ids['new'], $post, $rating])
        ->and($second->ids)->toBe(['new' => $second->ids['new']])
        ->and($second->ids['new'])->toBeGreaterThan($rating)
        ->and(StudioPlanVote::query()->findOrFail($post))
        ->vote_type->toBe(VoteType::Down)->offset_seconds->toBe(90)->sort_order->toBe(3)
        ->created_at->toEqual($created)
        ->and(StudioPlanVote::query()->findOrFail($rating)->rating_option_id)->toBe($this->other->id)
        ->and($second->lockVersion)->toBe(3);
});

it('deletes the votes left out, and only them', function () {
    $saved = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['key' => 'a', 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['key' => 'b', 'type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['key' => 'c', 'type' => 'vote_comment', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'discussionNodeId' => $this->comment->id, 'offsetSeconds' => 300]),
    ]);

    saveStudioVotes($this->admin, $this->plan, [
        studioVote(['id' => $saved->ids['b'], 'type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
    ]);

    expect(StudioPlanVote::query()->pluck('id')->all())->toBe([$saved->ids['b']]);
});

it('gives a vote another Persona or target, keeping its id — two votes even trading targets', function () {
    $saved = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['key' => 'on-comment', 'type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->comment->id, 'offsetSeconds' => 700]),
        studioVote(['key' => 'on-reply', 'type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'down', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 700]),
        studioVote(['key' => 'post', 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['key' => 'rating', 'type' => 'vote_rating', 'personaUserId' => $this->boris->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60]),
    ]);
    $id = $saved->ids;
    $created = StudioPlanVote::query()->findOrFail($id['on-comment'])->created_at;

    // The author's two votes trade comments; Anna's vote on the post becomes
    // Boris's; Boris's rating vote becomes Anna's vote on the post.
    saveStudioVotes($this->admin, $this->plan, [
        studioVote(['id' => $id['on-comment'], 'type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 700]),
        studioVote(['id' => $id['on-reply'], 'type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'down', 'discussionNodeId' => $this->comment->id, 'offsetSeconds' => 700]),
        studioVote(['id' => $id['post'], 'type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['id' => $id['rating'], 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'down', 'offsetSeconds' => 60]),
    ]);

    expect(StudioPlanVote::query()->orderBy('id')->get()->map(fn (StudioPlanVote $vote): array => [$vote->id, $vote->persona_user_id, $vote->target_key])->all())->toBe([
        [$id['on-comment'], $this->author->id, "comment:{$this->reply->id}"],
        [$id['on-reply'], $this->author->id, "comment:{$this->comment->id}"],
        [$id['post'], $this->boris->id, 'post'],
        [$id['rating'], $this->anna->id, 'post'],
    ])
        ->and(StudioPlanVote::query()->findOrFail($id['on-comment'])->created_at)->toEqual($created)
        ->and(StudioPlanVote::query()->findOrFail($id['rating']))
        ->type->toBe(StudioActionType::VotePost)->rating_group_id->toBeNull()->rating_option_id->toBeNull();
});

it('refuses votes the rules refuse, writing nothing and keeping the version', function () {
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);
    $before = DB::table('studio_plan_votes')->orderBy('id')->get();

    $refusal = votesRefusal(fn () => saveStudioVotes($this->admin, $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
    ]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['votes.1.personaUserId' => ['The post’s own author cannot vote on it. Choose another persona.']])
        ->and(DB::table('studio_plan_votes')->orderBy('id')->get())->toEqual($before)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('never reaches another plan\'s vote: an id that is not this plan\'s refuses the whole save', function () {
    $theirs = StudioPlanVote::factory()->create(['persona_user_id' => $this->anna->id]);
    $before = DB::table('studio_plan_votes')->where('id', $theirs->id)->first();

    $refusal = votesRefusal(fn () => saveStudioVotes($this->admin, $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['id' => $theirs->id, 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'down', 'offsetSeconds' => 60]),
    ]));

    expect($refusal->errors())->toBe(['votes.1.id' => ['This vote is not one of this plan’s. Reload the plan.']])
        ->and(DB::table('studio_plan_votes')->where('id', $theirs->id)->first())->toEqual($before)
        ->and(StudioPlanVote::query()->where('studio_plan_id', $this->plan->id)->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('refuses a save drawn from a version somebody else replaced, writing nothing over theirs', function () {
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);

    $refusal = votesRefusal(fn () => app(SaveStudioVotesAction::class)->handle($this->admin, $this->plan, new StudioVotesData([
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'down', 'offsetSeconds' => 60]),
    ]), 1));

    expect($refusal)->toBeInstanceOf(CannotManageStudioPlanException::class)
        ->and($refusal->isStale())->toBeTrue()
        ->and(StudioPlanVote::query()->pluck('persona_user_id')->all())->toBe([$this->anna->id])
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('refuses a plan past its Draft: approved, running, completed or cancelled', function (StudioPlanStatus $status) {
    $this->plan->forceFill(['status' => $status])->save();

    $refusal = votesRefusal(fn () => saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]));

    expect($refusal->isNotDraft())->toBeTrue()
        ->and(StudioPlanVote::query()->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
})->with([StudioPlanStatus::Approved, StudioPlanStatus::Running, StudioPlanStatus::Completed, StudioPlanStatus::Cancelled]);

it('refuses a plan deleted meanwhile', function () {
    $stale = StudioPlan::query()->findOrFail($this->plan->id);
    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, 1);

    expect(votesRefusal(fn () => app(SaveStudioVotesAction::class)->handle($this->admin, $stale, new StudioVotesData, 1))->isGone())->toBeTrue();
});

it('is an active administrator\'s alone, asked before and again on the locked row', function () {
    $member = User::factory()->create();

    expect(votesRefusal(fn () => saveStudioVotes($member, $this->plan, []))->isNotAllowed())->toBeTrue()
        ->and(votesRefusal(fn () => saveStudioVotes($this->anna, $this->plan, []))->isNotAllowed())->toBeTrue();

    // An administrator banned since the copy the caller holds was read: it
    // passes the first check, and the locked row refuses it.
    $this->admin->forceFill(['status' => UserStatus::Banned])->save();
    $stale = User::query()->findOrFail($this->admin->id)->forceFill(['status' => UserStatus::Active]);

    expect(votesRefusal(fn () => app(SaveStudioVotesAction::class)->handle($stale, $this->plan, new StudioVotesData, 1))->isNotAllowed())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('refuses a Persona deleted before the save takes its row, writing nothing', function () {
    $tombstoned = false;

    // Right before the users are locked — after the votes were checked.
    DB::beforeExecuting(function (string $sql) use (&$tombstoned): void {
        if (! $tombstoned && preg_match('/^\s*select\b.*\bfrom\s+["`]?users["`]?\s.*\bid["`]?\s*=/i', $sql) === 1 && str_contains($sql, 'limit')) {
            $tombstoned = true;
            DB::table('users')->where('id', $this->anna->id)->update(['status' => UserStatus::Deleted->value]);
        }
    });

    $refusal = votesRefusal(fn () => saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]));

    expect($tombstoned)->toBeTrue()
        ->and($refusal->errors())->toBe(['votes.0.personaUserId' => ['Choose a persona who is not deleted.']])
        ->and(StudioPlanVote::query()->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('keeps a limited, shadowbanned or banned Persona chosen earlier', function (UserStatus $status) {
    $this->anna->forceFill(['status' => $status])->save();

    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);

    expect(StudioPlanVote::query()->sole()->persona_user_id)->toBe($this->anna->id);
})->with([UserStatus::Limited, UserStatus::Shadowbanned, UserStatus::Banned]);

it('keeps a vote for an option archived since, and saves the rest around it', function () {
    $saved = saveStudioVotes($this->admin, $this->plan, [studioVote(['key' => 'r', 'type' => 'vote_rating', 'personaUserId' => $this->anna->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60])]);
    $this->option->update(['is_active' => false, 'archived_at' => now()]);

    saveStudioVotes($this->admin, $this->plan, [
        ...editedStudioVotes($this->plan, fn ($vote) => $vote),
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
    ]);

    expect(StudioPlanVote::query()->findOrFail($saved->ids['r']))
        ->rating_option_id->toBe($this->option->id)
        ->rating_group_id->toBe($this->group->id)
        ->and(StudioPlanVote::query()->count())->toBe(2);
});

it('takes the whole save back when a write of it is not saved', function (Closure $cancel) {
    $saved = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['key' => 'a', 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['key' => 'b', 'type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
    ]);
    $before = storedStudioVotes($this->plan);
    $cancel();

    expect(fn () => saveStudioVotes($this->admin, $this->plan, [
        studioVote(['id' => $saved->ids['a'], 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'down', 'offsetSeconds' => 90]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 900]),
    ]))->toThrow(RuntimeException::class);

    expect(storedStudioVotes($this->plan))->toBe($before)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
})->with([
    'a new vote' => [fn () => StudioPlanVote::creating(fn (StudioPlanVote $vote): ?bool => $vote->type === StudioActionType::VoteComment ? false : null)],
    'a change' => [fn () => StudioPlanVote::updating(fn (): bool => false)],
    'a deletion' => [fn () => StudioPlanVote::deleting(fn (): bool => false)],
    'the plan\'s new version' => [fn () => StudioPlan::updating(fn (): bool => false)],
]);

it('takes the whole save back on a database error midway', function () {
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);
    $before = storedStudioVotes($this->plan);
    StudioPlanVote::creating(function (StudioPlanVote $vote): void {
        if ($vote->persona_user_id === $this->author->id) {
            // A statement the database refuses, as a full disk or a lost connection would be.
            DB::statement('select * from a_table_that_is_not_there');
        }
    });

    expect(fn () => saveStudioVotes($this->admin, $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'down', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 900]),
    ]))->toThrow(QueryException::class);

    expect(storedStudioVotes($this->plan))->toBe($before)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('casts nothing: no vote, action, job or notification', function () {
    Bus::fake();
    Notification::fake();

    saveStudioVotes($this->admin, $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 900]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $this->boris->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60]),
    ]);

    expect(PostVote::query()->exists())->toBeFalse()
        ->and(CommentVote::query()->exists())->toBeFalse()
        ->and(RatingVote::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('locks the administrator and every voting Persona in ascending id order, then the plan', function () {
    $persona = User::factory()->persona()->create();
    $locks = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (preg_match('/^select \* from (users|studio_plans) where/', $sql, $match) === 1 && str_contains($sql, 'limit 1')) {
            $locks[] = $match[1] === 'users' ? (int) $query->bindings[0] : 'plan';
        }
    });

    saveStudioVotes($this->admin, $this->plan, [
        studioVote(['type' => 'vote_post', 'personaUserId' => $persona->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $persona->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60]),
    ]);

    $users = collect([$this->admin->id, $this->anna->id, $persona->id])->sort()->values()->all();

    // Neither the post's author nor a comment's Persona is locked: they are read from the locked plan's own rows.
    expect(array_slice($locks, -4))->toBe([...$users, 'plan'])
        ->and($locks)->not->toContain($this->author->id);
});

it('logs a save with its counts and its version, once committed', function () {
    Log::spy();

    $saved = saveStudioVotes($this->admin, $this->plan, [
        studioVote(['key' => 'a', 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_post', 'personaUserId' => $this->boris->id, 'voteType' => 'up', 'offsetSeconds' => 60]),
        studioVote(['type' => 'vote_comment', 'personaUserId' => $this->author->id, 'voteType' => 'up', 'discussionNodeId' => $this->reply->id, 'offsetSeconds' => 900]),
        studioVote(['type' => 'vote_rating', 'personaUserId' => $this->boris->id, 'ratingOptionId' => $this->option->id, 'offsetSeconds' => 60]),
    ]);
    saveStudioVotes($this->admin, $this->plan, [studioVote(['id' => $saved->ids['a'], 'type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.votes.saved'
        && array_intersect_key($context, array_flip(['studio_plan_id', 'admin_id', 'post_votes', 'comment_votes', 'rating_votes', 'removed', 'lock_version']))
            === ['studio_plan_id' => $this->plan->id, 'admin_id' => $this->admin->id, 'post_votes' => 2, 'comment_votes' => 1, 'rating_votes' => 1, 'removed' => 0, 'lock_version' => 2]);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.votes.saved'
        && $context['post_votes'] === 1 && $context['removed'] === 3 && $context['lock_version'] === 3);
});

it('logs nothing of a save an enclosing transaction rolls back', function () {
    Log::spy();

    DB::beginTransaction();
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);
    DB::rollBack();

    Log::shouldNotHaveReceived('info', fn (string $message): bool => $message === 'studio.votes.saved');
    expect(StudioPlanVote::query()->exists())->toBeFalse();
});

it('logs a refusal with its code and the fields that failed', function () {
    Log::spy();

    votesRefusal(fn () => saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'sideways', 'offsetSeconds' => 60])]));
    votesRefusal(fn () => app(SaveStudioVotesAction::class)->handle($this->admin, $this->plan, new StudioVotesData, 7));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.votes.rejected'
        && array_intersect_key($context, array_flip(['studio_plan_id', 'admin_id', 'code', 'fields']))
            === ['studio_plan_id' => $this->plan->id, 'admin_id' => $this->admin->id, 'code' => 'invalid_votes', 'fields' => ['votes.0.voteType']]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.votes.rejected'
        && $context['code'] === 'stale_version');
});

it('goes with its Draft when the Draft is deleted', function () {
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 60])]);

    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, $this->plan->fresh()->lock_version);

    expect(StudioPlanVote::query()->exists())->toBeFalse();
});

it('moves every vote with a new date for the post, unchanged', function () {
    saveStudioVotes($this->admin, $this->plan, [studioVote(['type' => 'vote_post', 'personaUserId' => $this->anna->id, 'voteType' => 'up', 'offsetSeconds' => 3600])]);
    $before = storedStudioVotes($this->plan);

    moveStudioPost($this->admin, $this->plan, StudioPlanPublicationMode::AtDate, '2024-12-24 18:30:00');

    expect($this->plan->fresh()->publish_at)->toEqual(CarbonImmutable::parse('2024-12-24 18:30:00'))
        ->and(storedStudioVotes($this->plan))->toBe($before);
});
