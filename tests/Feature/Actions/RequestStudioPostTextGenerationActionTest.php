<?php

use App\Actions\Studio\RequestStudioPostTextGenerationAction;
use App\Enums\StudioImageAnalysisStatus;
use App\Enums\StudioPlanStatus;
use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioPostTextGenerationException;
use App\Jobs\Studio\GenerateStudioPostTextsJob;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use App\Support\StudioAi\StudioImageDescription;
use App\Support\StudioAi\StudioPostTextContext;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * An administrator asks for a batch of post text suggestions: one queued
 * batch written from the photo's finished analysis, its job carrying two
 * ids, the plan itself untouched — and never a second paid request by
 * accident while one is on its way, whoever asks.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('queues one batch in the tone, written from the photo and its analysis, its job carrying only two ids', function () {
    $plan = studioPlanWithFinishedAnalysis(['lock_version' => 4]);

    $generation = requestStudioPostTexts($this->admin, $plan, StudioPostTextTone::Irony);

    expect($generation)
        ->studio_plan_id->toBe($plan->id)
        ->source_image_asset_id->toBe($plan->image_asset_id)
        ->source_analysis_hash->toBe(StudioImageDescription::from(StudioPlanImageAnalysisFactory::description())->hash())
        ->tone->toBe(StudioPostTextTone::Irony)
        ->status->toBe(StudioPostTextGenerationStatus::Queued)
        ->titles->toBeNull()
        ->and($generation->attempt_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    Queue::assertPushed(GenerateStudioPostTextsJob::class, 1);
    Queue::assertPushed(GenerateStudioPostTextsJob::class, function (GenerateStudioPostTextsJob $job) use ($generation): bool {
        expect(serialize($job))->not->toContain('O:20:"App\Models\StudioPlan"')
            ->and(serialize($job))->not->toContain(STUDIO_ANALYSIS_TEST_API_KEY)
            ->and(serialize($job))->not->toContain('A brown dog');

        return $job->generationId === $generation->id && $job->attemptId === $generation->attempt_id;
    });

    // Nothing is sent from here, and the plan is as it was.
    Http::assertNothingSent();
    expect($plan->fresh()->lock_version)->toBe(4);
});

it('logs the request with safe metadata only', function () {
    Log::spy();
    $plan = studioPlanWithFinishedAnalysis();

    $generation = requestStudioPostTexts($this->admin, $plan);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'studio.post_text_generation.requested'
        && $context['studio_plan_id'] === $plan->id
        && $context['attempt_id'] === $generation->attempt_id
        && $context['tone'] === 'cheeky'
        && ! str_contains(json_encode($context), 'brown dog'));
});

it('starts no second batch while one is on its way, however often it is asked', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $first = requestStudioPostTexts($this->admin, $plan);

    $again = requestStudioPostTexts($this->admin, $plan, StudioPostTextTone::Neutral);
    $fromAnother = requestStudioPostTexts(User::factory()->admin()->create(), $plan);
    $first->forceFill(['status' => StudioPostTextGenerationStatus::Running, 'started_at' => now()])->save();
    $whileRunning = requestStudioPostTexts($this->admin, $plan);

    expect([$again->id, $fromAnother->id, $whileRunning->id])->toBe([$first->id, $first->id, $first->id])
        ->and(StudioPlanTextGeneration::query()->count())->toBe(1);
    Queue::assertPushed(GenerateStudioPostTextsJob::class, 1);
});

it('starts another batch once the last has finished, keeping every earlier one', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $first = requestStudioPostTexts($this->admin, $plan);
    $first->forceFill(['status' => StudioPostTextGenerationStatus::Completed, 'titles' => ['kept'], 'descriptions' => ['kept']])->save();

    $second = requestStudioPostTexts($this->admin, $plan, StudioPostTextTone::Playful);

    expect($second->id)->not->toBe($first->id)
        ->and($second->tone)->toBe(StudioPostTextTone::Playful)
        ->and($first->fresh()->titles)->toBe(['kept'])
        ->and(StudioPlanTextGeneration::query()->count())->toBe(2);
    Queue::assertPushed(GenerateStudioPostTextsJob::class, 2);
});

it('takes a batch on its way far longer than any live one could as lost, and starts another', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $plan = studioPlanWithFinishedAnalysis();
    $lost = requestStudioPostTexts($this->admin, $plan);

    Carbon::setTestNow('2026-10-20 10:05:00');
    $next = requestStudioPostTexts($this->admin, $plan);

    expect($next->id)->not->toBe($lost->id)
        ->and($lost->fresh())->status->toBe(StudioPostTextGenerationStatus::Failed)->error_code->toBe(StudioPostTextGenerationError::UnexpectedFailure);
});

it('asks for nothing until the photo\'s analysis has finished with a valid description of it', function (Closure $arrange) {
    $plan = StudioPlan::factory()->withImage()->create();
    $arrange($plan);

    expect(fn () => requestStudioPostTexts($this->admin, $plan))
        ->toThrow(fn (CannotRequestStudioPostTextGenerationException $exception) => expect($exception->error)->toBe(StudioPostTextGenerationError::AnalysisMissing));

    expect(StudioPlanTextGeneration::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'no analysis' => [fn () => null],
    'an analysis waiting' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->create()],
    'an analysis running' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->running()->create()],
    'an analysis that failed' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->failed()->create()],
    'a re-run on its way over a result' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create(['status' => StudioImageAnalysisStatus::Queued])],
    'an analysis of the photo before' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->completed()->create([
        'studio_plan_id' => $plan->id,
        'source_image_asset_id' => MediaAsset::factory()->postImage()->create()->id,
    ])],
    'a stored result that is no valid description' => [fn (StudioPlan $plan) => StudioPlanImageAnalysis::factory()->forPlan($plan)->completed(['summary' => 'Only a summary'])->create()],
]);

it('asks for nothing for a plan with no photo', function () {
    $plan = StudioPlan::factory()->create();

    expect(fn () => app(RequestStudioPostTextGenerationAction::class)->handle($this->admin, $plan, 1, $plan->author_user_id, StudioPostTextTone::Cheeky))
        ->toThrow(CannotRequestStudioPostTextGenerationException::class);
});

it('keeps what a batch is written from: the analysis alone for a post with no author, and the author’s voice with it for one with an author', function () {
    $analysisHash = StudioImageDescription::from(StudioPlanImageAnalysisFactory::description())->hash();
    $alone = requestStudioPostTexts($this->admin, studioPlanWithFinishedAnalysis());

    $author = studioPersonaWithProfile(['character' => 'Blunt and brief.']);
    $voiced = requestStudioPostTexts($this->admin, studioPlanWithFinishedAnalysis(['author_user_id' => $author->id]));
    $context = StudioPostTextContext::ofPlan($voiced->plan, $voiced->plan->imageAnalysis);

    expect($alone->source_analysis_hash)->toBe($analysisHash)
        ->and($voiced->source_analysis_hash)->not->toBe($analysisHash)
        ->toBe($context->sourceHash())
        ->and($context->author?->character)->toBe('Blunt and brief.');
});

it('asks for nothing in the voice of an author the editor no longer shows', function (Closure $shown) {
    $author = studioPersonaWithProfile();
    $plan = studioPlanWithFinishedAnalysis(['author_user_id' => $author->id]);

    expect(fn () => app(RequestStudioPostTextGenerationAction::class)->handle($this->admin, $plan, (int) $plan->image_asset_id, $shown(), StudioPostTextTone::Cheeky))
        ->toThrow(fn (CannotRequestStudioPostTextGenerationException $exception) => expect($exception->error)->toBe(StudioPostTextGenerationError::AuthorChanged));

    expect(StudioPlanTextGeneration::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'another persona' => [fn () => studioPersonaWithProfile()->id],
    'no author' => [fn () => null],
]);

it('asks for nothing for a photo the editor no longer shows', function () {
    $plan = studioPlanWithFinishedAnalysis();
    $shown = $plan->image_asset_id;
    $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save();

    expect(fn () => requestStudioPostTexts($this->admin, $plan, imageAssetId: $shown))
        ->toThrow(fn (CannotRequestStudioPostTextGenerationException $exception) => expect($exception->error)->toBe(StudioPostTextGenerationError::StaleAnalysis));

    Queue::assertNothingPushed();
});

it('asks for nothing while writing texts is not configured', function () {
    configureStudioImageAnalysis(['api_key' => null]);
    $plan = studioPlanWithFinishedAnalysis();

    expect(fn () => requestStudioPostTexts($this->admin, $plan))
        ->toThrow(fn (CannotRequestStudioPostTextGenerationException $exception) => expect($exception->error)->toBe(StudioPostTextGenerationError::NotConfigured));

    Queue::assertNothingPushed();
});

it('is an active administrator\'s alone, for a plan still there and still a Draft', function (Closure $actor, Closure $change, string $expected) {
    $plan = studioPlanWithFinishedAnalysis();
    $change($plan);

    expect(fn () => requestStudioPostTexts($actor(), $plan))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect(match ($expected) {
            'not allowed' => $exception->isNotAllowed(),
            'gone' => $exception->isGone(),
            'not a draft' => $exception->isNotDraft(),
        })->toBeTrue());

    expect(StudioPlanTextGeneration::query()->exists())->toBeFalse();
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create(), fn () => null, 'not allowed'],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create(), fn () => null, 'not allowed'],
    'a deleted plan' => [fn () => User::factory()->admin()->create(), fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete(), 'gone'],
    'a plan past its Draft' => [fn () => User::factory()->admin()->create(), fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), 'not a draft'],
]);

it('ends the batch when the queue cannot take its job, leaving the plan as it was', function () {
    // A queue that is not there: the job cannot be pushed.
    Queue::swap(new QueueManager(app()));
    config(['queue.default' => 'nowhere']);
    $plan = studioPlanWithFinishedAnalysis();

    $generation = requestStudioPostTexts($this->admin, $plan);

    expect($generation)->status->toBe(StudioPostTextGenerationStatus::Failed)->error_code->toBe(StudioPostTextGenerationError::UnexpectedFailure)
        ->and($plan->fresh()->lock_version)->toBe(1);
});

it('deletes every batch with its Draft', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->count(2)->create();

    StudioPlan::query()->whereKey($plan->id)->delete();

    expect(StudioPlanTextGeneration::query()->exists())->toBeFalse();
});
