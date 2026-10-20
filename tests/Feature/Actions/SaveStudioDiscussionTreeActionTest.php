<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\SaveStudioDiscussionTreeAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Saving a Draft's whole discussion: every comment and reply written at once
 * — added, changed and deleted together, or not at all — each keeping its id,
 * under the plan's lock and version, and nothing published.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->plan = StudioPlan::factory()->create(['created_by_user_id' => $this->admin->id]);
    [$this->anna, $this->boris] = User::factory()->persona()->count(2)->create();
});

/** The discussion as stored: each comment's persona, text, place, time and parent's text. */
function storedDiscussion(StudioPlan $plan): array
{
    return $plan->discussionNodes()->with('parent')->get()
        ->map(fn (StudioDiscussionNode $node): array => [
            'persona' => $node->persona_user_id,
            'body' => $node->body,
            'sort' => $node->sort_order,
            'offset' => $node->offset_seconds,
            'parent' => $node->parent?->body,
        ])
        ->all();
}

/** The save refused, and why. */
function discussionRefusal(Closure $save): Throwable
{
    try {
        $save();
    } catch (CannotManageStudioPlanException|ValidationException $refusal) {
        return $refusal;
    }

    throw new RuntimeException('The save was not refused.');
}

it('saves an empty discussion, raising the version and writing nothing else', function () {
    $saved = saveStudioDiscussion($this->admin, $this->plan, []);

    expect($saved->lockVersion)->toBe(2)
        ->and($saved->nodes)->toHaveCount(0)
        ->and($this->plan->lock_version)->toBe(2)
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse();
});

it('saves comments and replies, a new reply answering a new comment of the same save by its key', function () {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'light', 'personaUserId' => $this->anna->id, 'body' => '  Lovely light.  ', 'offsetSeconds' => 60]),
        discussionNode(['parentKey' => 'light', 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 180]),
        discussionNode(['parentKey' => 'light', 'personaUserId' => $this->anna->id, 'body' => 'The boats!', 'sortOrder' => 1, 'offsetSeconds' => 300]),
        discussionNode(['key' => 'where', 'personaUserId' => $this->boris->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 120]),
    ]);

    $light = $saved->ids['light'];

    expect(storedDiscussion($this->plan))->toBe([
        ['persona' => $this->anna->id, 'body' => 'Lovely light.', 'sort' => 0, 'offset' => 60, 'parent' => null],
        ['persona' => $this->boris->id, 'body' => 'Agreed.', 'sort' => 0, 'offset' => 180, 'parent' => 'Lovely light.'],
        ['persona' => $this->boris->id, 'body' => 'Where is this?', 'sort' => 1, 'offset' => 120, 'parent' => null],
        ['persona' => $this->anna->id, 'body' => 'The boats!', 'sort' => 1, 'offset' => 300, 'parent' => 'Lovely light.'],
    ])
        ->and(array_keys($saved->ids))->toBe(['light', 'where'])
        ->and($saved->roots()->pluck('id')->all())->toBe([$light, $saved->ids['where']])
        ->and($saved->repliesTo($light)->pluck('body')->all())->toBe(['Agreed.', 'The boats!'])
        ->and($saved->lockVersion)->toBe(2);
});

it('keeps every comment\'s id through an edit, and gives new ones theirs', function () {
    $first = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'b', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 180]),
    ]);
    [$a, $b] = [$first->ids['a'], $first->ids['b']];

    $second = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['id' => $a, 'personaUserId' => $this->boris->id, 'body' => 'Such light.', 'offsetSeconds' => 90]),
        discussionNode(['id' => $b, 'parentId' => $a, 'personaUserId' => $this->anna->id, 'body' => 'Agreed, truly.', 'offsetSeconds' => 240]),
        discussionNode(['key' => 'c', 'parentId' => $a, 'personaUserId' => $this->anna->id, 'body' => 'And the boats.', 'sortOrder' => 1, 'offsetSeconds' => 300]),
    ]);

    expect($second->nodes->pluck('id')->all())->toBe([$a, $b, $second->ids['c']])
        ->and($second->ids['c'])->toBeGreaterThan($b)
        ->and(StudioDiscussionNode::query()->findOrFail($a))->body->toBe('Such light.')->persona_user_id->toBe($this->boris->id)->offset_seconds->toBe(90)
        ->and(StudioDiscussionNode::query()->findOrFail($b))->parent_id->toBe($a)->offset_seconds->toBe(240)
        ->and($second->lockVersion)->toBe(3);
});

it('moves a reply to another comment, and makes a reply a comment of its own', function () {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'b', 'personaUserId' => $this->anna->id, 'body' => 'Two.', 'sortOrder' => 1, 'offsetSeconds' => 60]),
        discussionNode(['key' => 'r', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Reply.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 's', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Another.', 'sortOrder' => 1, 'offsetSeconds' => 120]),
    ]);
    $id = $saved->ids;

    saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['id' => $id['a'], 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['id' => $id['b'], 'personaUserId' => $this->anna->id, 'body' => 'Two.', 'sortOrder' => 1, 'offsetSeconds' => 60]),
        discussionNode(['id' => $id['r'], 'parentId' => $id['b'], 'personaUserId' => $this->boris->id, 'body' => 'Reply.', 'offsetSeconds' => 120]),
        discussionNode(['id' => $id['s'], 'personaUserId' => $this->boris->id, 'body' => 'Another.', 'sortOrder' => 2, 'offsetSeconds' => 120]),
    ]);

    expect(StudioDiscussionNode::query()->findOrFail($id['r'])->parent_id)->toBe($id['b'])
        ->and(StudioDiscussionNode::query()->findOrFail($id['s'])->parent_id)->toBeNull();
});

it('deletes a comment left out with its replies, and a reply left out alone', function () {
    Log::spy();
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'One, first reply.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'a2', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'One, second reply.', 'sortOrder' => 1, 'offsetSeconds' => 120]),
        discussionNode(['key' => 'b', 'personaUserId' => $this->anna->id, 'body' => 'Two.', 'sortOrder' => 1, 'offsetSeconds' => 60]),
        discussionNode(['key' => 'b1', 'parentKey' => 'b', 'personaUserId' => $this->boris->id, 'body' => 'Two, first reply.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'b2', 'parentKey' => 'b', 'personaUserId' => $this->boris->id, 'body' => 'Two, second reply.', 'sortOrder' => 1, 'offsetSeconds' => 120]),
    ]);
    $id = $saved->ids;

    // Comment "One" left out with its replies; one reply of "Two" left out.
    saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['id' => $id['b'], 'personaUserId' => $this->anna->id, 'body' => 'Two.', 'sortOrder' => 1, 'offsetSeconds' => 60]),
        discussionNode(['id' => $id['b2'], 'parentId' => $id['b'], 'personaUserId' => $this->boris->id, 'body' => 'Two, second reply.', 'sortOrder' => 1, 'offsetSeconds' => 120]),
    ]);

    expect(StudioDiscussionNode::query()->orderBy('id')->pluck('id')->all())->toBe([$id['b'], $id['b2']]);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion.deleted_branch'
        && $context['root_id'] === $id['a']
        && $context['replies'] === 2);
});

it('refuses a reply to a comment the same save deletes, leaving the discussion as it was', function () {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Reply.', 'offsetSeconds' => 120]),
    ]);
    $before = storedDiscussion($this->plan);

    $refusal = discussionRefusal(fn () => saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['id' => $saved->ids['a1'], 'parentId' => $saved->ids['a'], 'personaUserId' => $this->boris->id, 'body' => 'Reply.', 'offsetSeconds' => 120]),
    ]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toHaveKey('nodes.0.parentId')
        ->and(storedDiscussion($this->plan))->toBe($before)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('keeps comments that share a moment and a place in the order they were given, by their ids', function () {
    $saved = saveStudioDiscussion($this->admin, $this->plan, collect(range(1, 5))->map(fn (int $index) => discussionNode([
        'personaUserId' => $this->anna->id,
        'body' => "Comment {$index}.",
        'offsetSeconds' => 60,
    ]))->all());

    expect($saved->nodes->pluck('body')->all())->toBe(['Comment 1.', 'Comment 2.', 'Comment 3.', 'Comment 4.', 'Comment 5.'])
        ->and($this->plan->discussionNodes()->pluck('body')->all())->toBe(['Comment 1.', 'Comment 2.', 'Comment 3.', 'Comment 4.', 'Comment 5.']);
});

it('moves the whole discussion with a new date for the post, unchanged', function () {
    $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => CarbonImmutable::parse('2021-05-01 10:00:00')])->save();
    saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Reply.', 'offsetSeconds' => 180]),
        discussionNode(['parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Later.', 'sortOrder' => 1, 'offsetSeconds' => 300]),
    ]);
    $before = storedDiscussion($this->plan);

    app(UpdateStudioPlanAction::class)->handle($this->admin, $this->plan, new StudioPlanData(
        publicationMode: StudioPlanPublicationMode::AtDate,
        publishAt: CarbonImmutable::parse('2024-12-24 18:30:00'),
    ), $this->plan->fresh()->lock_version);

    expect($this->plan->fresh()->publish_at->toDateTimeString())->toBe('2024-12-24 18:30:00')
        ->and(storedDiscussion($this->plan))->toBe($before);
});

it('refuses a save drawn from a version somebody else replaced, writing nothing over theirs', function () {
    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Theirs.'])]);

    $refusal = discussionRefusal(fn () => app(SaveStudioDiscussionTreeAction::class)->handle($this->admin, $this->plan, new StudioDiscussionData([
        discussionNode(['personaUserId' => $this->boris->id, 'body' => 'Mine.']),
    ]), 1));

    expect($refusal)->toBeInstanceOf(CannotManageStudioPlanException::class)
        ->and($refusal->isStale())->toBeTrue()
        ->and(StudioDiscussionNode::query()->pluck('body')->all())->toBe(['Theirs.'])
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('refuses a plan past its Draft: approved, running, completed or cancelled', function (StudioPlanStatus $status) {
    $this->plan->forceFill(['status' => $status])->save();

    $refusal = discussionRefusal(fn () => saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Late.'])]));

    expect($refusal->isNotDraft())->toBeTrue()
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
})->with([StudioPlanStatus::Approved, StudioPlanStatus::Running, StudioPlanStatus::Completed, StudioPlanStatus::Cancelled]);

it('is an active administrator\'s alone, asked before and again on the locked row', function () {
    $member = User::factory()->create();

    expect(discussionRefusal(fn () => saveStudioDiscussion($member, $this->plan, []))->isNotAllowed())->toBeTrue();

    // An administrator banned since the copy the caller holds was read: it
    // passes the first check, and the locked row refuses it.
    $this->admin->forceFill(['status' => UserStatus::Banned])->save();
    $stale = User::query()->findOrFail($this->admin->id)->forceFill(['status' => UserStatus::Active]);

    expect(discussionRefusal(fn () => app(SaveStudioDiscussionTreeAction::class)->handle($stale, $this->plan, new StudioDiscussionData, 1))->isNotAllowed())->toBeTrue()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('refuses a Persona deleted between the first check and the locked row, writing nothing', function () {
    $tombstoned = false;

    // Right before the users are locked — after the discussion was checked.
    DB::beforeExecuting(function (string $sql) use (&$tombstoned): void {
        if (! $tombstoned && preg_match('/^\s*select\b.*\bfrom\s+["`]?users["`]?\s.*\bid["`]?\s*=/i', $sql) === 1 && str_contains($sql, 'limit')) {
            $tombstoned = true;
            DB::table('users')->where('id', $this->anna->id)->update(['status' => UserStatus::Deleted->value]);
        }
    });

    $refusal = discussionRefusal(fn () => saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Lovely.'])]));

    expect($tombstoned)->toBeTrue()
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['nodes.0.personaUserId' => ['Choose a persona who is not deleted.']])
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('keeps a limited, shadowbanned or banned Persona chosen earlier', function (UserStatus $status) {
    $this->anna->forceFill(['status' => $status])->save();

    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Still mine.'])]);

    expect(StudioDiscussionNode::query()->sole()->persona_user_id)->toBe($this->anna->id);
})->with([UserStatus::Limited, UserStatus::Shadowbanned, UserStatus::Banned]);

it('never reaches another plan\'s comment: an id that is not this plan\'s refuses the whole save', function () {
    $other = StudioPlan::factory()->create();
    $theirs = StudioDiscussionNode::factory()->for($other, 'plan')->create(['body' => 'Theirs.']);
    $before = DB::table('studio_plan_discussion_nodes')->where('id', $theirs->id)->first();

    $refusal = discussionRefusal(fn () => saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Mine.']),
        discussionNode(['id' => $theirs->id, 'personaUserId' => $this->anna->id, 'body' => 'Mine now.']),
    ]));

    expect($refusal->errors())->toBe(['nodes.1.id' => ['This comment is not part of this plan’s discussion. Reload the plan.']])
        ->and(DB::table('studio_plan_discussion_nodes')->where('id', $theirs->id)->first())->toEqual($before)
        ->and(StudioDiscussionNode::query()->where('studio_plan_id', $this->plan->id)->exists())->toBeFalse()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('takes the whole save back when a write of it is not saved', function (Closure $cancel) {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'b', 'personaUserId' => $this->anna->id, 'body' => 'Two.', 'sortOrder' => 1, 'offsetSeconds' => 60]),
    ]);
    $before = storedDiscussion($this->plan);
    $cancel();

    expect(fn () => saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['id' => $saved->ids['a'], 'personaUserId' => $this->boris->id, 'body' => 'One, edited.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'c', 'personaUserId' => $this->boris->id, 'body' => 'Three.', 'sortOrder' => 2, 'offsetSeconds' => 90]),
    ]))->toThrow(RuntimeException::class);

    expect(storedDiscussion($this->plan))->toBe($before)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
})->with([
    'a new comment' => [fn () => StudioDiscussionNode::creating(fn (StudioDiscussionNode $node): ?bool => $node->body === 'Three.' ? false : null)],
    'a deletion' => [fn () => StudioDiscussionNode::deleting(fn (): bool => false)],
    'the plan\'s new version' => [fn () => StudioPlan::updating(fn (): bool => false)],
]);

it('takes the whole save back on any error midway', function () {
    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Kept.'])]);
    StudioDiscussionNode::creating(function (StudioDiscussionNode $node): void {
        if ($node->body === 'Second.') {
            throw new RuntimeException('The disk is full.');
        }
    });

    expect(fn () => saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['personaUserId' => $this->anna->id, 'body' => 'First.']),
        discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Second.', 'sortOrder' => 1]),
    ]))->toThrow(RuntimeException::class, 'The disk is full.');

    expect(StudioDiscussionNode::query()->pluck('body')->all())->toBe(['Kept.'])
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('publishes nothing: no post, comment, action, job or notification', function () {
    Bus::fake();
    Notification::fake();

    saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'One.', 'offsetSeconds' => 60]),
        discussionNode(['parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Reply.', 'offsetSeconds' => 120]),
    ]);

    expect(Post::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('locks the administrator and every Persona in ascending id order, then the plan', function () {
    $persona = User::factory()->persona()->create();
    $locks = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (preg_match('/^select \* from (users|studio_plans) where/', $sql, $match) === 1 && str_contains($sql, 'limit 1')) {
            $locks[] = $match[1] === 'users' ? (int) $query->bindings[0] : 'plan';
        }
    });

    saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['personaUserId' => $persona->id, 'body' => 'One.']),
        discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Two.']),
        discussionNode(['personaUserId' => $persona->id, 'body' => 'Three.']),
    ]);

    $users = collect([$this->admin->id, $this->anna->id, $persona->id])->sort()->values()->all();

    expect(array_slice($locks, -4))->toBe([...$users, 'plan']);
});

it('logs a save with its counts and its version, once committed — never the texts', function () {
    Log::spy();

    saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'A secret comment.', 'offsetSeconds' => 60]),
        discussionNode(['parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'A secret reply.', 'offsetSeconds' => 120]),
        discussionNode(['personaUserId' => $this->boris->id, 'body' => 'Another secret.', 'sortOrder' => 1, 'offsetSeconds' => 90]),
    ]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion.saved'
        && array_intersect_key($context, array_flip(['studio_plan_id', 'admin_id', 'roots', 'replies', 'lock_version']))
            === ['studio_plan_id' => $this->plan->id, 'admin_id' => $this->admin->id, 'roots' => 2, 'replies' => 1, 'lock_version' => 2]);
    Log::shouldNotHaveReceived('info', fn (string $message, array $context): bool => str_contains(json_encode($context), 'secret'));
});

it('logs nothing of a save an enclosing transaction rolls back', function () {
    Log::spy();

    DB::beginTransaction();
    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Lovely.'])]);
    DB::rollBack();

    Log::shouldNotHaveReceived('info', fn (string $message): bool => $message === 'studio.discussion.saved');
    expect(StudioDiscussionNode::query()->exists())->toBeFalse();
});

it('logs a refusal with its code and the fields that failed — never what they hold', function () {
    Log::spy();

    discussionRefusal(fn () => saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => str_repeat('secret ', 200)])]));
    discussionRefusal(fn () => app(SaveStudioDiscussionTreeAction::class)->handle($this->admin, $this->plan, new StudioDiscussionData, 7));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion.rejected'
        && array_intersect_key($context, array_flip(['studio_plan_id', 'admin_id', 'code', 'fields']))
            === ['studio_plan_id' => $this->plan->id, 'admin_id' => $this->admin->id, 'code' => 'invalid_discussion', 'fields' => ['nodes.0.body']]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion.rejected'
        && $context['code'] === 'stale_version');
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context): bool => str_contains(json_encode($context), 'secret'));
});

it('goes with its Draft when the Draft is deleted', function () {
    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'Lovely.'])]);

    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, $this->plan->fresh()->lock_version);

    expect(StudioDiscussionNode::query()->exists())->toBeFalse();
});
