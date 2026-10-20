<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Jobs\Studio\GenerateStudioDiscussionJob;
use App\Models\Comment;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The job that writes one discussion generation: one request at most, with
 * the post, the analysis and the Personas' characters as data — never the
 * photo — and a proposal kept only when it is a valid one for the plan as it
 * still is. Nothing reaches the plan's discussion, and nothing is sent twice.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioDiscussionGeneration();
    $this->admin = User::factory()->admin()->create();
    $this->plan = discussionReadyStudioPlan(['created_by_user_id' => $this->admin->id, 'description' => 'Boats asleep in the morning mist.']);
    $this->anna = studioPersonaWithProfile([
        'short_description' => 'A retired sailor.',
        'character' => 'Grumpy, warm underneath.',
        'speech_style' => 'Short sentences, nautical slang.',
        'topics' => 'Boats, weather, the old days.',
        'never_writes_about' => 'Politics.',
        'emoji_frequency' => PersonaEmojiFrequency::Occasional,
    ], ['display_name' => 'Anna the Sailor', 'username' => 'anna_private_handle']);
    $this->boris = studioPersonaWithProfile(['character' => 'An eager photographer.'], ['display_name' => 'Boris']);
});

/** A whole discussion of two comments, one with a reply, by the two Personas. */
function twoCommentAnswer(): array
{
    return ['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'Somebody definitely owns this harbour now.'),
        answeredComment('node_2', 'node_1', 'persona_2', 180, 'The seagulls might disagree.'),
        answeredComment('node_3', null, 'persona_2', 120, 'That mist is doing all the work.'),
    ]];
}

/** Asks for a whole discussion of two comments and one reply by Anna and Boris. */
function requestTwoComments($test): StudioPlanDiscussionGeneration
{
    return requestStudioDiscussion($test->admin, $test->plan, ['personaUserIds' => [$test->anna->id, $test->boris->id], 'rootCount' => 2, 'replyCount' => 1]);
}

it('runs within the queue\'s timings, once, failing on its timeout', function () {
    $job = new GenerateStudioDiscussionJob(1, 'attempt');

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(75)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and(config('content_studio.discussion_generation.timeout'))->toBeLessThan($job->timeout);
});

it('writes a whole discussion and keeps it as a proposal with the call\'s metadata, the plan and its discussion untouched', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(twoCommentAnswer()))]);
    $generation = requestTwoComments($this);
    $before = DB::table('studio_plans')->where('id', $this->plan->id)->first();

    runStudioDiscussionJob($generation);

    [$first, $second] = $this->anna->id < $this->boris->id ? [$this->anna, $this->boris] : [$this->boris, $this->anna];

    expect($generation->fresh())
        ->status->toBe(StudioDiscussionGenerationStatus::Completed)
        ->error_code->toBeNull()
        ->model->toBe('gpt-4.1-mini-2025-04-14')
        ->request_id->toBe('resp_studio_discussion_test')
        ->input_tokens->toBe(120)
        ->output_tokens->toBe(30)
        ->total_tokens->toBe(150)
        ->applied_at->toBeNull()
        ->and($generation->fresh()->finished_at)->not->toBeNull()
        ->and($generation->fresh()->result)->toBe(['nodes' => [
            ['key' => 'node_1', 'parent_key' => null, 'persona_user_id' => $first->id, 'body' => 'Somebody definitely owns this harbour now.', 'sort_order' => 0, 'offset_seconds' => 60],
            ['key' => 'node_2', 'parent_key' => 'node_1', 'persona_user_id' => $second->id, 'body' => 'The seagulls might disagree.', 'sort_order' => 0, 'offset_seconds' => 180],
            ['key' => 'node_3', 'parent_key' => null, 'persona_user_id' => $second->id, 'body' => 'That mist is doing all the work.', 'sort_order' => 1, 'offset_seconds' => 120],
        ]])
        // Completed is a proposal, not a discussion: nothing of it is in the plan.
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse()
        ->and(DB::table('studio_plans')->where('id', $this->plan->id)->first())->toEqual($before)
        ->and(Comment::query()->exists())->toBeFalse()
        ->and(Post::query()->exists())->toBeFalse()
        ->and(StudioAction::query()->exists())->toBeFalse();
});

it('sends the post, the analysis and each Persona\'s character as data — never the photo, nor anything private of an account', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(twoCommentAnswer()))]);

    runStudioDiscussionJob(requestTwoComments($this));

    Http::assertSentCount(1);
    Http::assertSent(function (HttpClientRequest $request): bool {
        $body = $request->data();
        $text = $body['input'][0]['content'][0]['text'];
        $encoded = json_encode($body);

        expect($body['store'])->toBeFalse()
            ->and($body)->not->toHaveKeys(['previous_response_id', 'tools', 'conversation', 'messages'])
            ->and($body['max_output_tokens'])->toBe(8000)
            ->and($body['text']['format'])->toMatchArray(['type' => 'json_schema', 'name' => 'studio_discussion', 'strict' => true])
            ->and($encoded)->not->toContain('input_image')->not->toContain('data:image')
            ->and($text)->toContain('A quiet harbour at dawn')
            ->toContain('Boats asleep in the morning mist.')
            ->toContain(StudioPlanImageAnalysisFactory::description()['summary'])
            ->toContain('Anna the Sailor')
            ->toContain('A retired sailor.')
            ->toContain('Grumpy, warm underneath.')
            ->toContain('Short sentences, nautical slang.')
            ->toContain('Boats, weather, the old days.')
            ->toContain('Politics.')
            ->toContain('"emoji": "sometimes"')
            ->toContain('exactly 2 comments on the post and exactly 1 replies')
            ->and($encoded)->not->toContain('anna_private_handle')
            ->not->toContain($this->anna->email)
            ->not->toContain('"engagement"')
            ->not->toContain('max_replies_per_day')
            ->not->toContain('user_id');

        return true;
    });
});

it('sends nothing when the job is delivered again, or after its generation has ended', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(twoCommentAnswer()))]);
    $generation = requestTwoComments($this);

    runStudioDiscussionJob($generation);
    runStudioDiscussionJob($generation);

    $ended = requestTwoComments($this);
    $ended->forceFill(['status' => StudioDiscussionGenerationStatus::Failed])->save();
    runStudioDiscussionJob($ended);

    Http::assertSentCount(1);
    expect($generation->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed);
});

it('sends nothing, and ends the generation with the reason, when what it is written from changed before the job ran', function (Closure $change, StudioDiscussionGenerationError $error) {
    Http::fake();
    $generation = requestTwoComments($this);

    $change($this);
    runStudioDiscussionJob($generation);

    Http::assertNothingSent();
    expect($generation->fresh())->status->toBe(StudioDiscussionGenerationStatus::Failed)->error_code->toBe($error);
})->with([
    'the title' => [fn ($test) => $test->plan->forceFill(['title' => 'Another title'])->save(), StudioDiscussionGenerationError::StaleContext],
    'the description' => [fn ($test) => $test->plan->forceFill(['description' => 'Another description.'])->save(), StudioDiscussionGenerationError::StaleContext],
    'the photo\'s analysis' => [fn ($test) => StudioPlanImageAnalysis::query()->where('studio_plan_id', $test->plan->id)->update(['result' => json_encode(StudioPlanImageAnalysisFactory::description(['summary' => 'A cat on a roof, seen from below.']))]), StudioDiscussionGenerationError::StaleContext],
    'a Persona\'s character' => [fn ($test) => $test->anna->personaProfile->forceFill(['character' => 'Cheerful now.'])->save(), StudioDiscussionGenerationError::StaleContext],
    'the discussion' => [fn ($test) => saveStudioDiscussion($test->admin, $test->plan, [discussionNode(['personaUserId' => $test->anna->id, 'body' => 'By hand.'])]), StudioDiscussionGenerationError::StaleContext],
    'the plan approved' => [fn ($test) => $test->plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), StudioDiscussionGenerationError::PlanClosed],
    'the integration switched off' => [fn () => config(['content_studio.image_analysis.enabled' => false]), StudioDiscussionGenerationError::NotConfigured],
]);

it('sends nothing for a plan deleted before the job ran, and leaves nothing behind', function () {
    Http::fake();
    $generation = requestTwoComments($this);

    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $this->plan, 1);
    runStudioDiscussionJob($generation);

    Http::assertNothingSent();
    expect(StudioPlanDiscussionGeneration::query()->exists())->toBeFalse();
});

it('ends the generation with the provider\'s reason, keeping the call\'s metadata and nothing it said', function (Closure $response, StudioDiscussionGenerationError $error) {
    Http::fake(['api.openai.com/*' => $response]);
    $generation = requestTwoComments($this);

    runStudioDiscussionJob($generation);

    expect($generation->fresh())->status->toBe(StudioDiscussionGenerationStatus::Failed)->error_code->toBe($error)->result->toBeNull()
        ->and(StudioDiscussionNode::query()->exists())->toBeFalse();
})->with([
    'HTTP 401' => [fn () => Http::response(['error' => ['message' => 'Incorrect API key sk-live-oops']], 401), StudioDiscussionGenerationError::AuthenticationFailed],
    'HTTP 429' => [fn () => Http::response(['error' => ['message' => 'Slow down.']], 429), StudioDiscussionGenerationError::RateLimited],
    'HTTP 500' => [fn () => Http::response('Oops', 500), StudioDiscussionGenerationError::ProviderUnavailable],
    'HTTP 400' => [fn () => Http::response(['error' => ['message' => 'Bad model.']], 400), StudioDiscussionGenerationError::ProviderRejected],
    'a timeout' => [fn () => throw new ConnectException('cURL error 28: Operation timed out', new PsrRequest('POST', 'https://api.openai.com/v1/responses')), StudioDiscussionGenerationError::ProviderTimeout],
    'a refusal' => [fn () => Http::response(openAiResponseBody('', ['output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]])), StudioDiscussionGenerationError::ProviderRefused],
    'an incomplete answer' => [fn () => Http::response(studioDiscussionBody(twoCommentAnswer(), ['status' => 'incomplete'])), StudioDiscussionGenerationError::InvalidProviderResponse],
    'a reply to a reply' => [fn () => Http::response(studioDiscussionBody(['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'One.'),
        answeredComment('node_2', 'node_1', 'persona_2', 120, 'Two.'),
        answeredComment('node_3', 'node_2', 'persona_1', 180, 'Three.'),
    ]])), StudioDiscussionGenerationError::InvalidProviderResponse],
    'a Persona not taking part' => [fn () => Http::response(studioDiscussionBody(['comments' => [
        answeredComment('node_1', null, 'persona_1', 60, 'One.'),
        answeredComment('node_2', 'node_1', 'persona_9', 120, 'Two.'),
        answeredComment('node_3', null, 'persona_2', 180, 'Three.'),
    ]])), StudioDiscussionGenerationError::InvalidProviderResponse],
]);

it('drops a proposal that came back after the post, a Persona or the discussion changed', function (Closure $change) {
    $generation = requestTwoComments($this);
    Http::fake(['api.openai.com/*' => function () use ($change) {
        // Meanwhile, while the provider writes.
        $change($this);

        return Http::response(studioDiscussionBody(twoCommentAnswer()));
    }]);

    runStudioDiscussionJob($generation);

    expect($generation->fresh())->status->toBe(StudioDiscussionGenerationStatus::Failed)->error_code->toBe(StudioDiscussionGenerationError::StaleContext)->result->toBeNull()
        ->and($generation->fresh()->model)->toBe('gpt-4.1-mini-2025-04-14');
})->with([
    'the title' => [fn ($test) => $test->plan->forceFill(['title' => 'Changed meanwhile'])->save()],
    'a Persona\'s speech' => [fn ($test) => $test->boris->personaProfile->forceFill(['speech_style' => 'All capitals.'])->save()],
    'the discussion' => [fn ($test) => saveStudioDiscussion($test->admin, $test->plan, [discussionNode(['personaUserId' => $test->anna->id, 'body' => 'By hand.'])])],
]);

it('keeps the proposal when only the plan\'s date was saved meanwhile — the discussion is relative to it', function () {
    $generation = requestTwoComments($this);
    Http::fake(['api.openai.com/*' => function () {
        $this->plan->forceFill(['publication_mode' => StudioPlanPublicationMode::AtDate, 'publish_at' => '2024-01-01 10:00:00', 'lock_version' => 5])->save();

        return Http::response(studioDiscussionBody(twoCommentAnswer()));
    }]);

    runStudioDiscussionJob($generation);

    expect($generation->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed);
});

it('holds no transaction open while it waits for the provider', function () {
    $generation = requestTwoComments($this);
    $outside = DB::transactionLevel();
    $during = null;

    Http::fake(['api.openai.com/*' => function () use (&$during) {
        $during = DB::transactionLevel();

        return Http::response(studioDiscussionBody(twoCommentAnswer()));
    }]);

    runStudioDiscussionJob($generation);

    expect($during)->toBe($outside);
});

it('writes a part again: a comment, a reply or a branch, naming its comments as the request names them', function (StudioDiscussionGenerationMode $mode, string $target, Closure $answer, Closure $expected) {
    $saved = saveStudioDiscussion($this->admin, $this->plan, [
        discussionNode(['key' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Lovely light.', 'offsetSeconds' => 60]),
        discussionNode(['key' => 'a1', 'parentKey' => 'a', 'personaUserId' => $this->boris->id, 'body' => 'Agreed.', 'offsetSeconds' => 120]),
        discussionNode(['key' => 'a2', 'parentKey' => 'a', 'personaUserId' => $this->anna->id, 'body' => 'Thank you.', 'sortOrder' => 1, 'offsetSeconds' => 180]),
        discussionNode(['key' => 'b', 'personaUserId' => $this->boris->id, 'body' => 'Where is this?', 'sortOrder' => 1, 'offsetSeconds' => 90]),
    ]);
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody($answer()))]);
    $generation = requestStudioDiscussion($this->admin, $this->plan, ['mode' => $mode, 'targetNodeId' => $saved->ids[$target]]);

    runStudioDiscussionJob($generation);

    expect($generation->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed)
        ->and($generation->fresh()->result)->toBe(['bodies' => $expected($saved->ids)]);
    Http::assertSent(function (HttpClientRequest $request) use ($mode): bool {
        $text = $request->data()['input'][0]['content'][0]['text'];

        // Every comment, by its alias, so the model knows what each answers.
        expect($text)->toContain('"comment": "existing_1"')->toContain('"reply": "existing_2"')->toContain('"comment": "existing_4"')
            ->and($request->data()['text']['format']['schema']['properties'])->toHaveKeys($mode === StudioDiscussionGenerationMode::RegenerateBranch ? ['comment', 'replies'] : ['text']);

        return true;
    });
})->with([
    'a comment' => [StudioDiscussionGenerationMode::RegenerateComment, 'a', fn () => ['text' => 'Such light this morning.'], fn (array $ids) => [['node_id' => $ids['a'], 'body' => 'Such light this morning.']]],
    'a reply' => [StudioDiscussionGenerationMode::RegenerateReply, 'a1', fn () => ['text' => 'Couldn’t agree more.'], fn (array $ids) => [['node_id' => $ids['a1'], 'body' => 'Couldn’t agree more.']]],
    'a branch' => [StudioDiscussionGenerationMode::RegenerateBranch, 'a', fn () => ['comment' => 'What a morning.', 'replies' => [
        ['reply' => 'existing_2', 'text' => 'The mist!'],
        ['reply' => 'existing_3', 'text' => 'I was there.'],
    ]], fn (array $ids) => [
        ['node_id' => $ids['a'], 'body' => 'What a morning.'],
        ['node_id' => $ids['a1'], 'body' => 'The mist!'],
        ['node_id' => $ids['a2'], 'body' => 'I was there.'],
    ]],
]);

it('ends its generation, and only its own unfinished one, when the job fails', function () {
    $generation = requestTwoComments($this);
    $earlier = StudioPlanDiscussionGeneration::factory()->completed()->create(['studio_plan_id' => $this->plan->id]);

    (new GenerateStudioDiscussionJob($generation->id, $generation->attempt_id))->failed(new RuntimeException('Boom.'));
    (new GenerateStudioDiscussionJob($earlier->id, $earlier->attempt_id))->failed(new RuntimeException('Boom.'));

    expect($generation->fresh())->status->toBe(StudioDiscussionGenerationStatus::Failed)->error_code->toBe(StudioDiscussionGenerationError::UnexpectedFailure)
        ->and($earlier->fresh()->status)->toBe(StudioDiscussionGenerationStatus::Completed);
});

it('logs its start and end with metadata only — no key, no post, no character, no comment', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(studioDiscussionBody(twoCommentAnswer()))]);
    $generation = requestTwoComments($this);

    runStudioDiscussionJob($generation);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.started'
        && $context['generation_id'] === $generation->id && $context['mode'] === 'full_discussion');
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.completed'
        && $context['generation_id'] === $generation->id
        && $context['nodes'] === 3
        && $context['model'] === 'gpt-4.1-mini-2025-04-14'
        && $context['provider_request_id'] === 'resp_studio_discussion_test'
        && $context['input_tokens'] === 120
        && is_int($context['latency_ms']));
    Log::shouldNotHaveReceived('info', fn (string $message, array $context): bool => str_contains(json_encode($context), STUDIO_ANALYSIS_TEST_API_KEY)
        || str_contains(json_encode($context), 'harbour')
        || str_contains(json_encode($context), 'Grumpy')
        || str_contains(json_encode($context), 'seagulls'));
});

it('logs a failure with its reason, and a proposal dropped for a changed context', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response('Oops', 503)]);

    runStudioDiscussionJob(requestTwoComments($this));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'studio.discussion_generation.failed'
        && $context['error_code'] === 'provider_unavailable'
        && $context['http_status'] === 503);
});
