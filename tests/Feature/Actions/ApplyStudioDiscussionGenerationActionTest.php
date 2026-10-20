<?php

use App\Actions\Studio\ApplyStudioDiscussionGenerationAction;
use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Exceptions\Studio\CannotApplyStudioDiscussionGenerationException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * An administrator takes a proposal into the plan: a whole discussion in
 * place of the plan's, or new texts for one part of it and nothing else —
 * through the one door every discussion goes through, together with
 * `applied_at`, once, and only while the plan still has what it was written
 * from.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioDiscussionGeneration();
    $this->admin = User::factory()->admin()->create();
    $this->plan = discussionReadyStudioPlan(['created_by_user_id' => $this->admin->id]);
    [$this->anna, $this->boris] = [studioPersonaWithProfile(), studioPersonaWithProfile()];
});

/** A whole discussion written and completed for the plan by Anna and Boris. */
function completedWholeDiscussion($test): StudioPlanDiscussionGeneration
{
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'Somebody owns this harbour now.'),
        answeredComment('node_2', 'node_1', 'persona_2', 180, 'The seagulls might disagree.'),
        answeredComment('node_3', null, 'persona_2', 120, 'That mist does all the work.'),
    ]]))]);
    $generation = requestStudioDiscussion($test->admin, $test->plan, ['personaUserIds' => [$test->anna->id, $test->boris->id], 'rootCount' => 2, 'replyCount' => 1]);
    runStudioDiscussionJob($generation);

    return $generation->fresh();
}

/**
 * A discussion saved by hand, and one part of it written again and completed.
 *
 * @return array{0: StudioPlanDiscussionGeneration, 1: array<string, int>}
 */
function completedPart($test, StudioDiscussionGenerationMode $mode, string $target, array $answer): array
{
    $saved = saveStudioDiscussion($test->admin, $test->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $test->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $test->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'a2', 'parentKey' => 'a', 'personaUserId' => $test->anna->id, 'body' => 'Thank you.', 'sortOrder' => 1, 'offsetSeconds' => 180]),
        discussionNode(['key' => 'b', 'personaUserId' => $test->boris->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 90]),
        discussionNode(['key' => 'b1', 'parentKey' => 'b', 'personaUserId' => $test->anna->id, 'body' => 'Brittany.', 'offsetSeconds' => 150]),
    ]);
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody($answer))]);
    $generation = requestStudioDiscussion($test->admin, $test->plan, ['mode' => $mode, 'targetNodeId' => $saved->ids[$target]]);
    runStudioDiscussionJob($generation);

    return [$generation->fresh(), $saved->ids];
}

/** Every comment of the plan's discussion as stored, by id. */
function discussionRows(StudioPlan $plan): array
{
    return DB::table('studio_plan_discussion_nodes')->where('studio_plan_id', $plan->id)->orderBy('id')->get()
        ->mapWithKeys(fn ($row): array => [$row->id => [(int) $row->parent_id, (int) $row->persona_user_id, $row->body, (int) $row->sort_order, (int) $row->offset_seconds]])
        ->all();
}

/** The proposal refused, and why. */
function applyRefusal(Closure $apply): Throwable
{
    try {
        $apply();
    } catch (CannotManageStudioPlanException|CannotApplyStudioDiscussionGenerationException|ValidationException $refusal) {
        return $refusal;
    }

    throw new RuntimeException('The proposal was not refused.');
}

it('takes a whole discussion into the plan in place of its own, each comment given its id, the version raised, applied_at stamped', function () {
    saveStudioDiscussion($this->admin, $this->plan, []);
    $generation = completedWholeDiscussion($this);

    $saved = applyStudioDiscussion($this->admin, $this->plan, $generation);

    expect($saved->lockVersion)->toBe(3)
        ->and($this->plan->fresh()->lock_version)->toBe(3)
        ->and($saved->roots()->pluck('body')->all())->toBe(['Somebody owns this harbour now.', 'That mist does all the work.'])
        ->and($saved->repliesTo($saved->roots()->first()->id)->pluck('body')->all())->toBe(['The seagulls might disagree.'])
        ->and(StudioDiscussionNode::query()->count())->toBe(3)
        ->and($generation->fresh()->applied_at)->not->toBeNull()
        ->and($generation->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed)
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(Post::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('changes only the texts of the part written again: no id, author, parent, place or time, and no other comment', function (StudioDiscussionGenerationMode $mode, string $target, array $answer, array $changed) {
    [$generation, $ids] = completedPart($this, $mode, $target, $answer);
    $before = discussionRows($this->plan);

    applyStudioDiscussion($this->admin, $this->plan, $generation);

    $after = discussionRows($this->plan);

    expect(array_keys($after))->toBe(array_keys($before));

    foreach ($before as $id => [$parent, $persona, $body, $sort, $offset]) {
        $key = array_search($id, $ids, true);

        expect($after[$id])->toBe([$parent, $persona, $changed[$key] ?? $body, $sort, $offset]);
    }
})->with([
    'a comment' => [StudioDiscussionGenerationMode::RegenerateComment, 'a', ['text' => 'Such light.'], ['a' => 'Such light.']],
    'a reply' => [StudioDiscussionGenerationMode::RegenerateReply, 'a2', ['text' => 'Much obliged.'], ['a2' => 'Much obliged.']],
    'a branch with two replies' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', ['comment' => 'What a morning.', 'replies' => [
        ['reply' => 'existing_2', 'text' => 'The mist!'],
        ['reply' => 'existing_3', 'text' => 'I was there.'],
    ]], ['a' => 'What a morning.', 'a1' => 'The mist!', 'a2' => 'I was there.']],
]);

it('takes a proposal in once: a second time changes nothing and raises no version', function () {
    $generation = completedWholeDiscussion($this);
    applyStudioDiscussion($this->admin, $this->plan, $generation);
    $rows = discussionRows($this->plan);

    $refusal = applyRefusal(fn () => applyStudioDiscussion($this->admin, $this->plan, $generation));

    expect($refusal->error)->toBe(StudioDiscussionGenerationError::AlreadyApplied)
        ->and(discussionRows($this->plan))->toBe($rows)
        ->and($this->plan->fresh()->lock_version)->toBe(2);
});

it('refuses a proposal taken in at a version somebody else replaced', function () {
    $generation = completedWholeDiscussion($this);

    $refusal = applyRefusal(fn () => app(ApplyStudioDiscussionGenerationAction::class)->handle($this->admin, $this->plan, $generation->id, 7));

    expect($refusal->isStale())->toBeTrue()
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and($generation->fresh()->applied_at)->toBeNull();
});

it('never takes in a proposal written from a context the plan no longer has, and never writes over a hand edit', function (Closure $change) {
    [$generation, $ids] = completedPart($this, StudioDiscussionGenerationMode::RegenerateComment, 'a', ['text' => 'Such light.']);
    $change($this, $ids);
    $rows = discussionRows($this->plan);
    $version = $this->plan->fresh()->lock_version;

    $refusal = applyRefusal(fn () => applyStudioDiscussion($this->admin, $this->plan, $generation));

    expect($refusal->error)->toBe(StudioDiscussionGenerationError::StaleContext)
        ->and(discussionRows($this->plan))->toBe($rows)
        ->and($this->plan->fresh()->lock_version)->toBe($version)
        ->and($generation->fresh()->applied_at)->toBeNull();
})->with([
    'a neighbour edited by hand' => [fn ($test, array $ids) => saveStudioDiscussion($test->admin, $test->plan, collect(StudioDiscussionData::of($test->plan)->nodes)
        ->map(fn ($node) => $node->id === $ids['b'] ? new StudioDiscussionNodeData(id: $node->id, personaUserId: $node->personaUserId, body: 'Edited by hand.', sortOrder: $node->sortOrder, offsetSeconds: $node->offsetSeconds) : $node)->all())],
    'the target deleted' => [fn ($test, array $ids) => saveStudioDiscussion($test->admin, $test->plan, collect(StudioDiscussionData::of($test->plan)->nodes)
        ->filter(fn ($node) => ! in_array($node->id, [$ids['a'], $ids['a1'], $ids['a2']], true))->values()->all())],
    'the target\'s author changed' => [fn ($test, array $ids) => saveStudioDiscussion($test->admin, $test->plan, collect(StudioDiscussionData::of($test->plan)->nodes)
        ->map(fn ($node) => $node->id === $ids['a'] ? new StudioDiscussionNodeData(id: $node->id, personaUserId: $test->boris->id, body: $node->body, sortOrder: $node->sortOrder, offsetSeconds: $node->offsetSeconds) : $node)->all())],
    'a reply moved under another comment' => [fn ($test, array $ids) => saveStudioDiscussion($test->admin, $test->plan, collect(StudioDiscussionData::of($test->plan)->nodes)
        ->map(fn ($node) => $node->id === $ids['a1'] ? new StudioDiscussionNodeData(id: $node->id, parentId: $ids['b'], personaUserId: $node->personaUserId, body: $node->body, sortOrder: 5, offsetSeconds: $node->offsetSeconds) : $node)->all())],
    'the title' => [fn ($test) => $test->plan->forceFill(['title' => 'Another title'])->save()],
    'a Persona\'s character' => [fn ($test) => $test->anna->personaProfile->forceFill(['character' => 'Someone else entirely.'])->save()],
]);

it('takes in nothing that is not a completed proposal of this plan', function (Closure $generation, StudioDiscussionGenerationError $error) {
    $refusal = applyRefusal(fn () => app(ApplyStudioDiscussionGenerationAction::class)->handle($this->admin, $this->plan, $generation($this)->id, 1));

    expect($refusal->error)->toBe($error)
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse();
})->with([
    'one still running' => [fn ($test) => requestStudioDiscussion($test->admin, $test->plan, ['personaUserIds' => [$test->anna->id], 'rootCount' => 1]), StudioDiscussionGenerationError::NotCompleted],
    'one that failed' => [fn ($test) => StudioPlanDiscussionGeneration::factory()->failed()->create(['studio_plan_id' => $test->plan->id]), StudioDiscussionGenerationError::NotCompleted],
    'another plan\'s' => [fn ($test) => StudioPlanDiscussionGeneration::factory()->completed()->create(), StudioDiscussionGenerationError::NotCompleted],
]);

it('refuses a plan past its Draft, and an administrator who may not', function () {
    $generation = completedWholeDiscussion($this);

    expect(applyRefusal(fn () => applyStudioDiscussion(User::factory()->create(), $this->plan, $generation))->isNotAllowed())->toBeTrue();

    $this->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

    expect(applyRefusal(fn () => applyStudioDiscussion($this->admin, $this->plan, $generation))->isNotDraft())->toBeTrue()
        ->and($generation->fresh()->applied_at)->toBeNull();
});

it('refuses a whole discussion by a Persona deleted since, through the discussion\'s own rules', function () {
    $generation = completedWholeDiscussion($this);
    $this->boris->forceFill(['status' => UserStatus::Deleted])->save();

    $refusal = applyRefusal(fn () => applyStudioDiscussion($this->admin, $this->plan, $generation));

    expect($refusal)->toBeInstanceOf(CannotApplyStudioDiscussionGenerationException::class)
        ->and($generation->fresh()->applied_at)->toBeNull()
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse();
});

it('takes back the whole of it when a write is not saved: no discussion, no applied_at', function (Closure $cancel) {
    $generation = completedWholeDiscussion($this);
    $cancel();

    expect(fn () => applyStudioDiscussion($this->admin, $this->plan, $generation))->toThrow(RuntimeException::class);

    expect(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and($generation->fresh()->applied_at)->toBeNull()
        ->and($this->plan->fresh()->lock_version)->toBe(1);
})->with([
    'a comment of it' => [fn () => StudioDiscussionNode::creating(fn (StudioDiscussionNode $node): ?bool => str_contains($node->body, 'mist') ? false : null)],
    'its applied_at' => [fn () => StudioPlanDiscussionGeneration::updating(fn (StudioPlanDiscussionGeneration $generation): ?bool => $generation->isDirty('applied_at') ? false : null)],
]);

it('keeps every earlier generation as history, whichever is taken in', function () {
    $first = completedWholeDiscussion($this);
    $second = completedWholeDiscussion($this);

    applyStudioDiscussion($this->admin, $this->plan, $first);

    expect(StudioPlanDiscussionGeneration::query()->count())->toBe(2)
        ->and($second->fresh())->status->toBe(StudioDiscussionGenerationStatus::Completed)->applied_at->toBeNull()
        // Written from the discussion as it was; the plan's is another now.
        ->and(applyRefusal(fn () => applyStudioDiscussion($this->admin, $this->plan, $second))->error)->toBe(StudioDiscussionGenerationError::StaleContext);
});

it('keeps the history of a part once the comment it wrote again is deleted', function () {
    [$generation, $ids] = completedPart($this, StudioDiscussionGenerationMode::RegenerateComment, 'b', ['text' => 'Where on earth?']);

    saveStudioDiscussion($this->admin, $this->plan, collect(StudioDiscussionData::of($this->plan)->nodes)
        ->filter(fn ($node) => ! in_array($node->id, [$ids['b'], $ids['b1']], true))->values()->all());

    expect($generation->fresh())->target_node_id->toBeNull()
        ->and($generation->fresh()->targetNodeId())->toBe($ids['b'])
        ->and($generation->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed);
});

it('logs a proposal taken in, and one refused, with ids and codes only', function () {
    Log::spy();
    $generation = completedWholeDiscussion($this);

    applyStudioDiscussion($this->admin, $this->plan, $generation);
    applyRefusal(fn () => applyStudioDiscussion($this->admin, $this->plan, $generation));

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.applied'
        && $context['generation_id'] === $generation->id
        && $context['studio_plan_id'] === $this->plan->id
        && $context['admin_id'] === $this->admin->id
        && $context['nodes'] === 3
        && $context['lock_version'] === 2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.rejected'
        && $context['error_code'] === 'already_applied');
    Log::shouldNotHaveReceived('info', fn (string $message, array $context): bool => str_contains(json_encode($context), 'seagulls'));
});
