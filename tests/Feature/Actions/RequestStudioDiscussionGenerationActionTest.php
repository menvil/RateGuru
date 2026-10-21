<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\RequestStudioDiscussionGenerationAction;
use App\Data\Studio\StudioDiscussionGenerationData;
use App\Enums\PersonaEngagement;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Enums\StudioPlanStatus;
use App\Enums\StudioPostTextTone;
use App\Enums\UserStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioDiscussionGenerationException;
use App\Jobs\Studio\GenerateStudioDiscussionJob;
use App\Models\Comment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\StudioAi\StudioDiscussionContext;
use Carbon\CarbonImmutable;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * An administrator asks for a discussion to be written: one generation is
 * queued — its job carrying two ids and nothing else — written from what the
 * plan has now, and nothing of the plan changes. Nothing is sent from here,
 * nor twice by accident.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioDiscussionGeneration();
    $this->admin = User::factory()->admin()->create();
    $this->plan = discussionReadyStudioPlan(['created_by_user_id' => $this->admin->id]);
    [$this->anna, $this->boris] = [studioPersonaWithProfile(), studioPersonaWithProfile()];
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The request refused, and why. */
function discussionRequestRefusal(Closure $request): Throwable
{
    try {
        $request();
    } catch (CannotManageStudioPlanException|CannotRequestStudioDiscussionGenerationException|ValidationException $refusal) {
        return $refusal;
    }

    throw new RuntimeException('The request was not refused.');
}

it('queues one whole discussion by the Personas chosen, its job carrying only two ids, the plan untouched', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $before = DB::table('studio_plans')->where('id', $this->plan->id)->first();

    $generation = requestStudioDiscussion($this->admin, $this->plan, [
        'personaUserIds' => [$this->boris->id, $this->anna->id],
        'rootCount' => 3,
        'replyCount' => 4,
        'tone' => StudioPostTextTone::Irony,
    ]);

    expect($generation)
        ->studio_plan_id->toBe($this->plan->id)
        ->requested_by_user_id->toBe($this->admin->id)
        ->mode->toBe(StudioDiscussionGenerationMode::FullDiscussion)
        ->target_node_id->toBeNull()
        ->persona_user_ids->toBe(collect([$this->anna->id, $this->boris->id])->sort()->values()->all())
        ->status->toBe(StudioDiscussionGenerationStatus::Queued)
        ->source_lock_version->toBe(1)
        ->source_image_asset_id->toBe($this->plan->image_asset_id)
        ->result->toBeNull()
        ->applied_at->toBeNull()
        ->and($generation->parameters)->toMatchArray(['root_count' => 3, 'reply_count' => 4, 'tone' => 'irony', 'expected_lock_version' => 1])
        // The post's texts it is written for, so the editor can say once they change.
        ->and($generation->parameters['source_text_fingerprint'])->toBe(StudioDiscussionContext::textFingerprint($this->plan->title, $this->plan->description))
        ->and($generation->source_analysis_hash)->toHaveLength(64)
        ->and($generation->source_discussion_hash)->toHaveLength(64)
        ->and($generation->source_personas_hash)->toHaveLength(64)
        ->and($generation->requested_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        // Asking writes nothing to the plan: not a field, not its version.
        ->and(DB::table('studio_plans')->where('id', $this->plan->id)->first())->toEqual($before)
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(Post::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();

    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, fn (GenerateStudioDiscussionJob $job): bool => $job->generationId === $generation->id
        && $job->attemptId === $generation->attempt_id
        && array_keys(get_object_vars($job)) === array_keys(get_object_vars(new GenerateStudioDiscussionJob(1, 'a')))
        && ! str_contains(serialize($job), 'harbour'));
    Http::assertNothingSent();
});

it('starts no second generation while one is on its way, whoever asks and however often', function () {
    $first = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);
    $again = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);
    $other = requestStudioDiscussion(User::factory()->admin()->create(), $this->plan, ['personaUserIds' => [$this->boris->id], 'rootCount' => 5]);

    expect($again->id)->toBe($first->id)
        ->and($other->id)->toBe($first->id)
        ->and(StudioPlanDiscussionGeneration::query()->count())->toBe(1);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
});

it('starts another once the last has finished and been taken in, keeping every earlier one', function () {
    $first = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);
    $first->forceFill(['status' => StudioDiscussionGenerationStatus::Completed, 'result' => ['nodes' => []], 'applied_at' => now()])->save();

    $second = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);

    expect($second->id)->not->toBe($first->id)
        ->and(StudioPlanDiscussionGeneration::query()->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed);
});

it('returns the last proposal finished and not taken in while the plan could still take it, whatever is asked: nothing new is paid for', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [answeredComment('node_1', null, 'persona_1', 60, 'Lovely light.')]]))]);
    $finished = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 1]);
    runStudioDiscussionJob($finished);

    $again = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->boris->id], 'rootCount' => 3, 'replyCount' => 2]);

    expect($again->id)->toBe($finished->id)
        ->and($again)->status->toBe(StudioDiscussionGenerationStatus::Completed)->applied_at->toBeNull()
        ->and(StudioPlanDiscussionGeneration::query()->count())->toBe(1);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 1);
    Http::assertSentCount(1);
});

it('asks anew once the last proposal can no longer be taken in: its context changed, or it was taken in', function (Closure $since) {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(['comments' => [answeredComment('node_1', null, 'persona_1', 60, 'Lovely light.')]]))]);
    $finished = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 1]);
    runStudioDiscussionJob($finished);
    $since($this, $finished->fresh());

    $next = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->boris->id], 'rootCount' => 1]);

    expect($next->id)->not->toBe($finished->id)
        ->and($next->status)->toBe(StudioDiscussionGenerationStatus::Queued)
        ->and($finished->fresh())->status->toBe(StudioDiscussionGenerationStatus::Completed);
    Queue::assertPushed(GenerateStudioDiscussionJob::class, 2);
})->with([
    'the post retitled' => [fn ($test) => $test->plan->forceFill(['title' => 'Mist on the harbour'])->save()],
    'a comment written by hand' => [fn ($test) => saveStudioDiscussion($test->admin, $test->plan, [discussionNode(['key' => 'a', 'personaUserId' => $test->boris->id, 'body' => 'Mine.', 'offsetSeconds' => 60])])],
    'its Persona deleted' => [fn ($test) => $test->anna->forceFill(['status' => UserStatus::Deleted])->save()],
    'taken in' => [fn ($test, $finished) => applyStudioDiscussion($test->admin, $test->plan, $finished)],
]);

it('takes one on its way far longer than any live one could as lost, and starts another', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $lost = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);

    Carbon::setTestNow('2026-10-20 10:03:00');
    $next = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);

    expect($next->id)->not->toBe($lost->id)
        ->and($lost->fresh())->status->toBe(StudioDiscussionGenerationStatus::Failed)->error_code->toBe(StudioDiscussionGenerationError::UnexpectedFailure);
});

it('writes a part of the discussion again by its own authors, whatever Personas are named', function (StudioDiscussionGenerationMode $mode, Closure $target, Closure $authors) {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 120]),
    ]);

    $generation = requestStudioDiscussion($this->admin, $this->plan, ['mode' => $mode, 'targetNodeId' => $target($saved->ids), 'personaUserIds' => [User::factory()->persona()->create()->id]]);

    expect($generation)->mode->toBe($mode)->target_node_id->toBe($target($saved->ids))
        ->and($generation->persona_user_ids)->toBe($authors($this))
        ->and($generation->targetNodeId())->toBe($target($saved->ids))
        ->and($generation->source_lock_version)->toBe(2)
        // Only a whole tree says which texts it was written for.
        ->and($generation->parameters)->not->toHaveKey('source_text_fingerprint');
})->with([
    'a comment' => [StudioDiscussionGenerationMode::RegenerateComment, fn (array $ids) => $ids['a'], fn ($test) => [$test->anna->id]],
    'a reply' => [StudioDiscussionGenerationMode::RegenerateReply, fn (array $ids) => $ids['a1'], fn ($test) => [$test->boris->id]],
    'a branch' => [StudioDiscussionGenerationMode::RegenerateBranch, fn (array $ids) => $ids['a'], fn ($test) => collect([$test->anna->id, $test->boris->id])->sort()->values()->all()],
]);

it('refuses a part the discussion does not hold at that level, or another plan\'s', function (StudioDiscussionGenerationMode $mode, Closure $target) {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 120]),
    ]);
    $theirs = StudioDiscussionNode::factory()->create();

    $refusal = discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['mode' => $mode, 'targetNodeId' => $target($saved->ids, $theirs)]));

    expect($refusal->error)->toBe(StudioDiscussionGenerationError::InvalidTarget)
        ->and(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
})->with([
    'a reply named as a comment' => [StudioDiscussionGenerationMode::RegenerateComment, fn (array $ids) => $ids['a1']],
    'a comment named as a reply' => [StudioDiscussionGenerationMode::RegenerateReply, fn (array $ids) => $ids['a']],
    'a reply named as a branch' => [StudioDiscussionGenerationMode::RegenerateBranch, fn (array $ids) => $ids['a1']],
    'a comment that is not there' => [StudioDiscussionGenerationMode::RegenerateComment, fn () => 2_000_000_000],
    'another plan\'s comment' => [StudioDiscussionGenerationMode::RegenerateComment, fn (array $ids, StudioDiscussionNode $theirs) => $theirs->id],
]);

it('asks for nothing until the photo\'s analysis has finished with a valid description of the plan\'s photo', function (Closure $arrange) {
    $arrange($this->plan);

    $refusal = discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]));

    expect($refusal->error)->toBe(StudioDiscussionGenerationError::AnalysisMissing)
        ->and(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'no analysis' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::query()->where('studio_plan_id', $plan->id)->delete()],
    'an analysis still running' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::query()->where('studio_plan_id', $plan->id)->update(['status' => 'running'])],
    'an analysis of another photo' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::query()->where('studio_plan_id', $plan->id)->update(['source_image_asset_id' => MediaAsset::factory()->postImage()->create()->id])],
]);

it('asks for nothing before the post has its final title, never picking one of the suggestions', function () {
    $this->plan->forceFill(['title' => null])->save();

    expect(discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]))->error)
        ->toBe(StudioDiscussionGenerationError::IncompletePost);

    // A description may be missing, as a post's may.
    $this->plan->forceFill(['title' => 'A quiet harbour', 'description' => null])->save();

    expect(requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2])->status)->toBe(StudioDiscussionGenerationStatus::Queued);
});

it('wants one to eight Personas chosen, each once, each still one and not deleted', function (Closure $personas, string $expected) {
    $refusal = discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => $personas($this), 'rootCount' => 2]));

    expect($refusal instanceof CannotRequestStudioDiscussionGenerationException ? $refusal->error->value : array_key_first($refusal->errors()))->toBe($expected)
        ->and(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
})->with([
    'nobody' => [fn () => [], 'no_personas'],
    'nine' => [fn () => User::factory()->persona()->count(9)->create()->pluck('id')->all(), 'personaUserIds'],
    'one twice' => [fn ($test) => [$test->anna->id, $test->anna->id], 'personaUserIds.0'],
    'an ordinary member' => [fn () => [User::factory()->create()->id], 'invalid_persona'],
    'an administrator' => [fn () => [User::factory()->admin()->create()->id], 'invalid_persona'],
    'a deleted Persona' => [fn () => [User::factory()->persona()->create(['status' => UserStatus::Deleted])->id], 'invalid_persona'],
    'nobody at all' => [fn () => [2_000_000_000], 'invalid_persona'],
]);

it('lets a limited, shadowbanned or banned Persona take part, and one whose replies are switched off', function (Closure $persona) {
    $generation = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$persona()->id], 'rootCount' => 2]);

    expect($generation->status)->toBe(StudioDiscussionGenerationStatus::Queued);
})->with([
    'limited' => [fn () => studioPersonaWithProfile([], ['status' => UserStatus::Limited])],
    'shadowbanned' => [fn () => studioPersonaWithProfile([], ['status' => UserStatus::Shadowbanned])],
    'banned' => [fn () => studioPersonaWithProfile([], ['status' => UserStatus::Banned])],
    'engagement off, no replies a day' => [fn () => studioPersonaWithProfile(['engagement' => PersonaEngagement::Off, 'max_replies_per_day' => 0])],
]);

it('keeps a whole discussion within forty comments and replies, with at least one comment on the post', function (int $roots, int $replies, string $field) {
    $refusal = discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => $roots, 'replyCount' => $replies]));

    expect($refusal->errors())->toHaveKey($field);
})->with([
    'no comment on the post' => [0, 3, 'rootCount'],
    'forty-one in all' => [20, 21, 'replyCount'],
    'forty-one comments' => [41, 0, 'rootCount'],
]);

it('asks for nothing while discussion generation is not configured', function () {
    config(['content_studio.image_analysis.enabled' => false]);

    expect(discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]))->error)
        ->toBe(StudioDiscussionGenerationError::NotConfigured);

    // A discussion is still saved by hand.
    saveStudioDiscussion($this->admin, $this->plan, [discussionNode(['personaUserId' => $this->anna->id, 'body' => 'By hand.'])]);

    expect(StudioDiscussionNode::query()->count())->toBe(1);
});

it('is an active administrator\'s alone, for a plan still a Draft at the version the editor was drawn from', function (Closure $arrange, Closure $check) {
    [$admin, $version] = $arrange($this);

    $refusal = discussionRequestRefusal(fn () => app(RequestStudioDiscussionGenerationAction::class)->handle($admin, $this->plan, new StudioDiscussionGenerationData(
        mode: StudioDiscussionGenerationMode::FullDiscussion, expectedLockVersion: $version, personaUserIds: [$this->anna->id], rootCount: 2,
    )));

    expect($check($refusal))->toBeTrue()
        ->and(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
})->with([
    'a member' => [fn ($test) => [User::factory()->create(), 1], fn ($refusal) => $refusal->isNotAllowed()],
    'a stale version' => [fn ($test) => [$test->admin, 7], fn ($refusal) => $refusal->isStale()],
    'an approved plan' => [function ($test) {
        $test->plan->forceFill(['status' => StudioPlanStatus::Approved])->save();

        return [$test->admin, 1];
    }, fn ($refusal) => $refusal->isNotDraft()],
]);

it('ends the generation when the queue cannot take its job, leaving the plan as it was', function () {
    // A queue that is not there: the job cannot be pushed.
    Queue::swap(new QueueManager(app()));
    config(['queue.default' => 'nowhere']);

    $generation = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);

    expect($generation)->status->toBe(StudioDiscussionGenerationStatus::Failed)->error_code->toBe(StudioDiscussionGenerationError::UnexpectedFailure)
        ->and($this->plan->fresh()->lock_version)->toBe(1);
});

it('logs a request and a refusal with ids, the mode and codes only — never a text or a character', function () {
    Log::spy();
    $secret = studioPersonaWithProfile(['character' => 'A secret character.', 'speech_style' => 'A secret style.']);

    $generation = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$secret->id], 'rootCount' => 2]);
    $this->plan->forceFill(['title' => null])->save();
    $generation->forceFill(['status' => StudioDiscussionGenerationStatus::Failed])->save();
    discussionRequestRefusal(fn () => requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$secret->id], 'rootCount' => 2]));

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.requested'
        && $context['generation_id'] === $generation->id
        && $context['studio_plan_id'] === $this->plan->id
        && $context['admin_id'] === $this->admin->id
        && $context['mode'] === 'full_discussion'
        && $context['status'] === 'queued');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.rejected'
        && $context['error_code'] === 'incomplete_post');
    Log::shouldNotHaveReceived('info', fn (string $message, array $context): bool => str_contains(json_encode($context), 'secret') || str_contains(json_encode($context), 'harbour'));
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context): bool => str_contains(json_encode($context), 'secret'));
});

it('deletes every generation with its Draft', function () {
    requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);

    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, 1);

    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
});

it('keeps the moment it was asked as UTC', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));

    $generation = requestStudioDiscussion($this->admin, $this->plan, ['personaUserIds' => [$this->anna->id], 'rootCount' => 2]);

    expect(DB::table('studio_plan_discussion_generations')->where('id', $generation->id)->value('requested_at'))->toStartWith('2026-10-20 10:00:00');
});
