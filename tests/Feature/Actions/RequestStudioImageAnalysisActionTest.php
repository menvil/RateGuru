<?php

use App\Actions\Studio\RequestStudioImageAnalysisAction;
use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioImageAnalysisException;
use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * An administrator asks for a Draft's saved photo to be analysed: one queued
 * attempt, its job carrying two ids, the plan itself untouched — and never a
 * second paid request by accident: not on a double click, not from a second
 * administrator, not for a result somebody else has already replaced.
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

function requestAnalysis(User $admin, StudioPlan $plan, ?int $imageAssetId = null, ?string $replacing = null): StudioPlanImageAnalysis
{
    return app(RequestStudioImageAnalysisAction::class)->handle($admin, $plan, $imageAssetId ?? (int) $plan->image_asset_id, $replacing);
}

it('queues one attempt for the photo, its job carrying only the analysis and attempt ids', function () {
    $plan = StudioPlan::factory()->withImage()->create();

    $analysis = requestAnalysis($this->admin, $plan);

    expect($analysis->studio_plan_id)->toBe($plan->id)
        ->and($analysis->source_image_asset_id)->toBe($plan->image_asset_id)
        ->and($analysis->status)->toBe(StudioImageAnalysisStatus::Queued)
        ->and($analysis->attempt_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($analysis->requested_at)->not->toBeNull()
        ->and($analysis->started_at)->toBeNull()
        ->and($analysis->result)->toBeNull();

    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, function (AnalyzeStudioPlanImageJob $job) use ($analysis): bool {
        expect(array_keys(get_object_vars($job)))->toContain('analysisId', 'attemptId')
            ->and(serialize($job))->not->toContain('O:20:"App\Models\StudioPlan"')
            ->and(serialize($job))->not->toContain(STUDIO_ANALYSIS_TEST_API_KEY);

        return $job->analysisId === $analysis->id && $job->attemptId === $analysis->attempt_id;
    });

    // Nothing is sent from here.
    Http::assertNothingSent();
});

it('leaves the plan as it was — its fields, its version and its time', function () {
    Carbon::setTestNow('2026-10-19 10:00:00');
    $plan = StudioPlan::factory()->withImage()->create(['title' => 'A harbour', 'lock_version' => 4]);
    $before = StudioPlan::query()->findOrFail($plan->id)->getAttributes();

    Carbon::setTestNow('2026-10-19 10:05:00');
    requestAnalysis($this->admin, $plan);

    expect(StudioPlan::query()->findOrFail($plan->id)->getAttributes())->toBe($before);
});

it('logs the request with its plan, attempt and status and nothing else', function () {
    Log::spy();
    $plan = StudioPlan::factory()->withImage()->create();

    $analysis = requestAnalysis($this->admin, $plan);

    Log::shouldHaveReceived('info')->with('studio.image_analysis.requested', Mockery::on(fn (array $context): bool => $context['studio_plan_id'] === $plan->id
        && $context['attempt_id'] === $analysis->attempt_id
        && $context['status'] === 'queued'
        && ! array_key_exists('result', $context)
        && ! str_contains(json_encode($context), STUDIO_ANALYSIS_TEST_API_KEY)));
});

it('starts nothing new while an attempt for the photo is on its way — a double click, or a second administrator', function (string $status) {
    $plan = StudioPlan::factory()->withImage()->create();
    $first = requestAnalysis($this->admin, $plan);
    StudioPlanImageAnalysis::query()->whereKey($first->id)->update(['status' => $status]);

    $again = requestAnalysis($this->admin, $plan);
    $another = requestAnalysis(User::factory()->admin()->create(), $plan, replacing: $first->attempt_id);

    expect($again->attempt_id)->toBe($first->attempt_id)
        ->and($another->attempt_id)->toBe($first->attempt_id)
        ->and(StudioPlanImageAnalysis::query()->count())->toBe(1);

    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
})->with(['queued', 'running']);

it('starts nothing for a photo that already has a result, unless that result is asked to be replaced', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $completed = StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();

    $analyze = requestAnalysis($this->admin, $plan);

    expect($analyze->attempt_id)->toBe($completed->attempt_id)
        ->and($analyze->status)->toBe(StudioImageAnalysisStatus::Completed);
    Queue::assertNothingPushed();
});

it('re-runs a result once, keeping it until a new one replaces it', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $completed = StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();

    $rerun = requestAnalysis($this->admin, $plan, replacing: $completed->attempt_id);

    expect($rerun->id)->toBe($completed->id)
        ->and($rerun->attempt_id)->not->toBe($completed->attempt_id)
        ->and($rerun->status)->toBe(StudioImageAnalysisStatus::Queued)
        ->and($rerun->result)->toBe(StudioPlanImageAnalysisFactory::description())
        ->and($rerun->model)->toBe($completed->model)
        ->and($rerun->finished_at?->equalTo($completed->finished_at))->toBeTrue();

    // A second administrator re-running the same result, after the first did, starts nothing.
    $second = requestAnalysis(User::factory()->admin()->create(), $plan, replacing: $completed->attempt_id);

    expect($second->attempt_id)->toBe($rerun->attempt_id);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('starts nothing when the result asked to be replaced has already been replaced by a newer one', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $newer = StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();

    $stale = requestAnalysis($this->admin, $plan, replacing: '00000000-0000-4000-8000-000000000000');

    expect($stale->attempt_id)->toBe($newer->attempt_id);
    Queue::assertNothingPushed();
});

it('tries a failed attempt again on request, keeping the last result for the photo', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $failed = StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();
    $failed->forceFill(['status' => StudioImageAnalysisStatus::Failed, 'error_code' => StudioImageAnalysisError::RateLimited])->save();

    $retry = requestAnalysis($this->admin, $plan, replacing: $failed->attempt_id);

    expect($retry->status)->toBe(StudioImageAnalysisStatus::Queued)
        ->and($retry->error_code)->toBeNull()
        ->and($retry->attempt_id)->not->toBe($failed->attempt_id)
        ->and($retry->result)->toBe(StudioPlanImageAnalysisFactory::description());
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('lets a lost attempt be replaced only once it has waited or run longer than a live one could', function (StudioImageAnalysisStatus $status) {
    Carbon::setTestNow('2026-10-19 12:00:00');
    config(['content_studio.image_analysis.stale_after_seconds' => 180]);
    $plan = StudioPlan::factory()->withImage()->create();
    $lost = StudioPlanImageAnalysis::factory()->forPlan($plan)->create([
        'status' => $status,
        'requested_at' => now(),
        'started_at' => $status === StudioImageAnalysisStatus::Running ? now() : null,
    ]);

    Carbon::setTestNow('2026-10-19 12:02:59');
    expect(requestAnalysis($this->admin, $plan, replacing: $lost->attempt_id)->attempt_id)->toBe($lost->attempt_id);

    Carbon::setTestNow('2026-10-19 12:03:00');
    $replaced = requestAnalysis($this->admin, $plan, replacing: $lost->attempt_id);

    expect($replaced->attempt_id)->not->toBe($lost->attempt_id)
        ->and($replaced->status)->toBe(StudioImageAnalysisStatus::Queued);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
})->with([
    'queued' => [StudioImageAnalysisStatus::Queued],
    'running' => [StudioImageAnalysisStatus::Running],
]);

it('starts afresh for a new photo, dropping the result of the previous one', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $old = StudioPlanImageAnalysis::factory()->forPlan($plan)->completed()->create();
    $newPhoto = MediaAsset::factory()->postImage()->create();
    $plan->forceFill(['image_asset_id' => $newPhoto->id])->save();

    $analysis = requestAnalysis($this->admin, $plan, $newPhoto->id);

    expect($analysis->id)->toBe($old->id)
        ->and($analysis->source_image_asset_id)->toBe($newPhoto->id)
        ->and($analysis->attempt_id)->not->toBe($old->attempt_id)
        ->and($analysis->status)->toBe(StudioImageAnalysisStatus::Queued)
        ->and($analysis->result)->toBeNull()
        ->and($analysis->model)->toBeNull()
        ->and($analysis->request_id)->toBeNull()
        ->and($analysis->total_tokens)->toBeNull()
        ->and($analysis->finished_at)->toBeNull();
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('refuses a photo other than the one the editor showed, and a plan with no photo', function (Closure $plan, Closure $shown, StudioImageAnalysisError $error) {
    $plan = $plan();

    try {
        app(RequestStudioImageAnalysisAction::class)->handle($this->admin, $plan, $shown($plan));
        $this->fail('The request was not refused.');
    } catch (CannotRequestStudioImageAnalysisException $exception) {
        expect($exception->error)->toBe($error);
    }

    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'a replaced photo' => [fn () => StudioPlan::factory()->withImage()->create(), fn () => MediaAsset::factory()->postImage()->create()->id, StudioImageAnalysisError::StaleImage],
    'no photo' => [fn () => StudioPlan::factory()->create(), fn () => MediaAsset::factory()->postImage()->create()->id, StudioImageAnalysisError::ImageMissing],
]);

it('refuses, queueing nothing and writing nothing, while analysis is not configured', function (array $settings) {
    configureStudioImageAnalysis($settings);
    $plan = StudioPlan::factory()->withImage()->create();

    try {
        requestAnalysis($this->admin, $plan);
        $this->fail('The request was not refused.');
    } catch (CannotRequestStudioImageAnalysisException $exception) {
        expect($exception->error)->toBe(StudioImageAnalysisError::NotConfigured)
            ->and($exception->getMessage())->toBe('Photo analysis is not configured.');
    }

    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'off' => [['enabled' => false]],
    'no key' => [['api_key' => '']],
]);

it('queues nothing for a plan that is gone or past its Draft, nor for anyone but an active administrator', function (Closure $admin, Closure $plan) {
    $plan = $plan();

    expect(fn () => requestAnalysis($admin(), $plan))->toThrow(CannotManageStudioPlanException::class);

    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'a deleted plan' => [fn () => User::factory()->admin()->create(), fn () => tap(StudioPlan::factory()->withImage()->create(), fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete())],
    'an approved plan' => [fn () => User::factory()->admin()->create(), fn () => StudioPlan::factory()->withImage()->status(StudioPlanStatus::Approved)->create()],
    'a moderator' => [fn () => User::factory()->moderator()->create(), fn () => StudioPlan::factory()->withImage()->create()],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create(), fn () => StudioPlan::factory()->withImage()->create()],
]);

it('asks again on the locked row whether the administrator may still manage plans', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    // Demoted after the copy in hand was read: only the row says so.
    User::query()->whereKey($this->admin->id)->update(['role' => 'user']);

    expect(fn () => requestAnalysis($this->admin, $plan))->toThrow(CannotManageStudioPlanException::class);

    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('ends the attempt as an unexpected failure when the queue cannot take its job', function () {
    // A queue that is not there: the job cannot be pushed.
    Queue::swap(new QueueManager(app()));
    config(['queue.default' => 'nowhere']);
    $plan = StudioPlan::factory()->withImage()->create();

    $analysis = requestAnalysis($this->admin, $plan);

    expect($analysis->status)->toBe(StudioImageAnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe(StudioImageAnalysisError::UnexpectedFailure);
});
