<?php

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Enums\StudioPlanStatus;
use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The queued job behind Analyze photo: it claims its attempt before anything
 * else, so a job delivered twice — or one of an attempt replaced since — sends
 * nothing; it checks the plan as it is now; it makes one request with no
 * transaction open; and it keeps the result only while it still describes
 * the plan's photo, never touching the plan itself.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    $this->admin = User::factory()->admin()->create();
});

it('runs within the queue\'s timings, once, failing on its timeout', function () {
    $job = new AnalyzeStudioPlanImageJob(1, 'attempt');

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(config('content_studio.image_analysis.timeout'))->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('analyses the photo and keeps the description with the call\'s metadata', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody())]);
    $plan = studioPlanWithStoredPhoto(['lock_version' => 3]);
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    runStudioImageAnalysisJob($analysis);

    $analysis->refresh();
    expect($analysis->status)->toBe(StudioImageAnalysisStatus::Completed)
        ->and($analysis->result)->toBe(StudioPlanImageAnalysisFactory::description())
        ->and($analysis->model)->toBe('gpt-4.1-mini-2025-04-14')
        ->and($analysis->request_id)->toBe('resp_studio_analysis_test')
        ->and([$analysis->input_tokens, $analysis->output_tokens, $analysis->total_tokens])->toBe([120, 30, 150])
        ->and($analysis->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($analysis->started_at)->not->toBeNull()
        ->and($analysis->finished_at)->not->toBeNull()
        ->and($analysis->error_code)->toBeNull();

    // The feed variant is what was sent, inline.
    $variant = MediaVariant::query()->where('media_asset_id', $plan->image_asset_id)->sole();
    Http::assertSentCount(1);
    Http::assertSent(fn (HttpClientRequest $request): bool => $request->data()['input'][0]['content'][1]['image_url']
        === 'data:image/jpeg;base64,'.base64_encode(Storage::disk('public')->get($variant->path)));

    // The plan is as it was: no field, no version, no time changed.
    expect(StudioPlan::query()->findOrFail($plan->id)->lock_version)->toBe(3);
});

it('sends nothing when the job is delivered again, or after its attempt was replaced', function () {
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody())]);
    $plan = studioPlanWithStoredPhoto();
    $first = requestStudioImageAnalysis($this->admin, $plan);

    runStudioImageAnalysisJob($first);
    runStudioImageAnalysisJob($first);

    Http::assertSentCount(1);

    // Re-run, then the first attempt's job turns up once more: nothing to claim.
    $second = requestStudioImageAnalysis($this->admin, $plan, replacing: $first->attempt_id);
    runStudioImageAnalysisJob($first);

    Http::assertSentCount(1);
    expect($second->refresh()->status)->toBe(StudioImageAnalysisStatus::Queued);
});

it('sends nothing for a plan deleted before the job ran', function () {
    Http::fake();
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);
    StudioPlan::query()->whereKey($plan->id)->delete();

    runStudioImageAnalysisJob($analysis);

    Http::assertNothingSent();
    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
});

it('sends nothing, and ends the attempt with the reason, when the plan changed before the job ran', function (Closure $change, StudioImageAnalysisError $error) {
    Http::fake();
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);
    $change($plan);

    runStudioImageAnalysisJob($analysis);

    Http::assertNothingSent();
    expect($analysis->refresh()->status)->toBe(StudioImageAnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe($error);
})->with([
    'the photo replaced' => [fn (StudioPlan $plan) => $plan->forceFill(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])->save(), StudioImageAnalysisError::StaleImage],
    'the photo removed' => [fn (StudioPlan $plan) => $plan->forceFill(['image_asset_id' => null])->save(), StudioImageAnalysisError::StaleImage],
    'past its Draft' => [fn (StudioPlan $plan) => $plan->forceFill(['status' => StudioPlanStatus::Approved])->save(), StudioImageAnalysisError::PlanClosed],
    'analysis switched off' => [fn () => configureStudioImageAnalysis(['enabled' => false]), StudioImageAnalysisError::NotConfigured],
]);

it('sends nothing, and says the photo is unavailable, when its file cannot be read', function () {
    Http::fake();
    $plan = StudioPlan::factory()->withImage()->create();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    runStudioImageAnalysisJob($analysis);

    Http::assertNothingSent();
    expect($analysis->refresh()->error_code)->toBe(StudioImageAnalysisError::ImageUnavailable);
});

it('ends the attempt with the provider\'s reason, the plan and its photo untouched', function (int $status, StudioImageAnalysisError $error) {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'nope']], $status)]);
    $plan = studioPlanWithStoredPhoto(['title' => 'A harbour']);
    $before = StudioPlan::query()->findOrFail($plan->id)->getAttributes();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    runStudioImageAnalysisJob($analysis);

    expect($analysis->refresh()->status)->toBe(StudioImageAnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe($error)
        ->and($analysis->result)->toBeNull()
        ->and(StudioPlan::query()->findOrFail($plan->id)->getAttributes())->toBe($before)
        ->and(MediaAsset::query()->whereKey($plan->image_asset_id)->exists())->toBeTrue();
})->with([
    [401, StudioImageAnalysisError::AuthenticationFailed],
    [429, StudioImageAnalysisError::RateLimited],
    [503, StudioImageAnalysisError::ProviderUnavailable],
]);

it('keeps the last result for the photo when a re-run of it fails', function () {
    $plan = studioPlanWithStoredPhoto();
    $completed = StudioPlanImageAnalysisFactory::new()->forPlan($plan)->completed()->create();
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody(['summary' => '']))]);

    $rerun = requestStudioImageAnalysis($this->admin, $plan, replacing: $completed->attempt_id);
    runStudioImageAnalysisJob($rerun);

    expect($rerun->refresh()->status)->toBe(StudioImageAnalysisStatus::Failed)
        ->and($rerun->error_code)->toBe(StudioImageAnalysisError::InvalidProviderResponse)
        ->and($rerun->result)->toBe($completed->result)
        ->and($rerun->model)->toBe($completed->model)
        ->and($rerun->request_id)->toBe($completed->request_id);
});

it('replaces the last result with the new one when a re-run succeeds', function () {
    $plan = studioPlanWithStoredPhoto();
    $completed = StudioPlanImageAnalysisFactory::new()->forPlan($plan)->completed()->create();
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody(['summary' => 'A grey cat sleeps on a windowsill.']))]);

    $rerun = requestStudioImageAnalysis($this->admin, $plan, replacing: $completed->attempt_id);
    runStudioImageAnalysisJob($rerun);

    expect($rerun->refresh()->status)->toBe(StudioImageAnalysisStatus::Completed)
        ->and($rerun->result['summary'])->toBe('A grey cat sleeps on a windowsill.')
        ->and($rerun->request_id)->toBe('resp_studio_analysis_test');
});

it('holds no transaction open while it waits for the provider', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);
    $outside = DB::transactionLevel();
    $during = null;

    Http::fake(function () use (&$during) {
        $during = DB::transactionLevel();

        return Http::response(studioImageDescriptionBody());
    });

    runStudioImageAnalysisJob($analysis);

    expect($during)->toBe($outside);
});

it('drops a result that came back after the photo was replaced, keeping the new photo unanalysed', function (Closure $change) {
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    Http::fake(function () use ($plan, $change) {
        $change($plan);

        return Http::response(studioImageDescriptionBody());
    });

    runStudioImageAnalysisJob($analysis);

    $analysis->refresh();
    $plan->refresh();
    expect($analysis->status)->toBe(StudioImageAnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe(StudioImageAnalysisError::StaleImage)
        ->and($analysis->result)->toBeNull()
        ->and($analysis->isFor($plan->image_asset_id))->toBeFalse();
})->with([
    'replaced' => [fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->update(['image_asset_id' => MediaAsset::factory()->postImage()->create()->id])],
    'removed' => [fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->update(['image_asset_id' => null])],
]);

it('leaves nothing behind for a plan deleted while its photo was being analysed', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    Http::fake(function () use ($plan) {
        StudioPlan::query()->whereKey($plan->id)->delete();

        return Http::response(studioImageDescriptionBody());
    });

    runStudioImageAnalysisJob($analysis);

    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse()
        ->and(StudioPlan::query()->exists())->toBeFalse();
});

it('keeps the result when only the texts were saved meanwhile, writing nothing of the plan', function () {
    $plan = studioPlanWithStoredPhoto(['title' => 'Before', 'lock_version' => 1]);
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    Http::fake(function () use ($plan) {
        // An editor's save landing while the request is out.
        StudioPlan::query()->whereKey($plan->id)->update(['title' => 'Typed meanwhile', 'lock_version' => 2]);

        return Http::response(studioImageDescriptionBody());
    });

    runStudioImageAnalysisJob($analysis);

    $plan->refresh();
    expect($analysis->refresh()->status)->toBe(StudioImageAnalysisStatus::Completed)
        ->and($plan->title)->toBe('Typed meanwhile')
        ->and($plan->lock_version)->toBe(2);
});

it('ends its attempt, and only its own unfinished one, when the job fails', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    (new AnalyzeStudioPlanImageJob($analysis->id, $analysis->attempt_id))->failed(new RuntimeException('Worker timed out'));

    expect($analysis->refresh()->status)->toBe(StudioImageAnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe(StudioImageAnalysisError::UnexpectedFailure);

    // A finished attempt is not touched, nor one replaced since.
    $completed = StudioPlanImageAnalysisFactory::new()->completed()->create();
    (new AnalyzeStudioPlanImageJob($completed->id, $completed->attempt_id))->failed();
    (new AnalyzeStudioPlanImageJob($completed->id, '00000000-0000-4000-8000-000000000000'))->failed();

    expect($completed->refresh()->status)->toBe(StudioImageAnalysisStatus::Completed);
});

it('logs the attempt\'s start and end with metadata only — no key, no photo, no description', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[] = ['message' => $event->message, 'context' => $event->context];
    });
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody())]);
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    runStudioImageAnalysisJob($analysis);

    $events = array_column($logged, 'message');
    expect($events)->toContain('studio.image_analysis.requested', 'studio.image_analysis.started', 'studio.image_analysis.completed');

    $completed = collect($logged)->firstWhere('message', 'studio.image_analysis.completed')['context'];
    expect($completed)->toMatchArray([
        'studio_plan_id' => $plan->id,
        'attempt_id' => $analysis->attempt_id,
        'status' => 'completed',
        'model' => 'gpt-4.1-mini-2025-04-14',
        'provider_request_id' => 'resp_studio_analysis_test',
        'input_tokens' => 120,
        'output_tokens' => 30,
    ]);

    $everything = json_encode($logged, JSON_THROW_ON_ERROR);
    $variant = MediaVariant::query()->where('media_asset_id', $plan->image_asset_id)->sole();
    expect($everything)
        ->not->toContain(STUDIO_ANALYSIS_TEST_API_KEY)
        ->not->toContain('Authorization')
        ->not->toContain(base64_encode(Storage::disk('public')->get($variant->path)))
        ->not->toContain('data:image')
        ->not->toContain(StudioPlanImageAnalysisFactory::description()['summary'])
        ->not->toContain('Describe only what is visible');
});

it('logs a failure with its reason and a dropped result as discarded', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[$event->message] = $event->context;
    });
    $plan = studioPlanWithStoredPhoto();
    $calls = 0;

    // Rate limited first; then, on the retry, the photo is taken off while the request is out.
    Http::fake(function () use ($plan, &$calls) {
        if (++$calls === 1) {
            return Http::response([], 429);
        }

        StudioPlan::query()->whereKey($plan->id)->update(['image_asset_id' => null]);

        return Http::response(studioImageDescriptionBody());
    });

    runStudioImageAnalysisJob(requestStudioImageAnalysis($this->admin, $plan));

    expect($logged['studio.image_analysis.failed'])->toMatchArray(['studio_plan_id' => $plan->id, 'error_code' => 'rate_limited', 'http_status' => 429]);

    runStudioImageAnalysisJob(requestStudioImageAnalysis($this->admin, $plan, replacing: StudioPlanImageAnalysis::query()->sole()->attempt_id));

    expect($logged['studio.image_analysis.discarded'])->toMatchArray(['studio_plan_id' => $plan->id, 'reason' => 'image_changed', 'error_code' => 'stale_image', 'total_tokens' => 150]);
});
