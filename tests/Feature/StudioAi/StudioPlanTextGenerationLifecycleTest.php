<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPostTextGenerationStatus;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;
use App\Support\StudioAi\StudioPostTextContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A plan's text suggestions through the plan's own life: its photo replaced,
 * its analysis re-run, its texts saved, the Draft deleted. A batch stays
 * stored, but counts only for the photo and the analysis it was written
 * from; it never holds a photo back from the media lifecycle, and writing
 * one never stands in the way of a save of the texts.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    $this->admin = User::factory()->admin()->create();
});

/** Whether the batch is one the editor shows and lets be used for the plan as it is now. */
function isCurrentBatch(StudioPlanTextGeneration $batch): bool
{
    $plan = StudioPlan::query()->with('imageAnalysis')->findOrFail($batch->studio_plan_id);
    $context = StudioPostTextContext::of($plan->image_asset_id, $plan->imageAnalysis);

    return $context !== null && $batch->fresh()->isFor($context->imageAssetId, $context->analysisHash);
}

it('keeps every batch of a plan, gone with the plan, naming its photo without holding it', function () {
    $foreignKeys = collect(Schema::getForeignKeys('studio_plan_text_generations'));

    expect($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->first()['columns'])->toBe(['studio_plan_id'])
        ->and($foreignKeys->first()['foreign_table'])->toBe('studio_plans')
        ->and(strtolower((string) $foreignKeys->first()['on_delete']))->toBe('cascade');

    $indexes = collect(Schema::getIndexes('studio_plan_text_generations'));

    expect($indexes->filter(fn (array $index): bool => $index['unique'])->pluck('columns')->all())->toContain(['attempt_id'])
        ->and($indexes->pluck('columns')->all())->toContain(['studio_plan_id', 'status']);
});

it('writes a batch from the photo it analysed, and stops counting it once the photo is replaced, releasing the old photo regardless', function () {
    $plan = studioPlanWithStoredPhoto();
    Http::fakeSequence('api.openai.com/*')->push(studioImageDescriptionBody())->push(studioPostTextsBody());
    runStudioImageAnalysisJob(requestStudioImageAnalysis($this->admin, $plan));
    $batch = requestStudioPostTexts($this->admin, $plan->refresh());
    runStudioPostTextsJob($batch);
    $oldPhoto = MediaAsset::query()->findOrFail($plan->image_asset_id);

    expect($batch->fresh()->status)->toBe(StudioPostTextGenerationStatus::Completed)
        ->and(isCurrentBatch($batch))->toBeTrue();

    app(SetStudioPlanImageAction::class)->handle($this->admin, $plan, UploadedFile::fake()->image('new.jpg', 64, 64), StudioPlan::query()->findOrFail($plan->id)->lock_version);

    expect(isCurrentBatch($batch))->toBeFalse()
        // Kept as it was, naming the old photo; it held nothing.
        ->and($batch->fresh())->status->toBe(StudioPostTextGenerationStatus::Completed)->source_image_asset_id->toBe($oldPhoto->id)
        ->and(app(MediaReferenceChecker::class)->isReferenced($oldPhoto))->toBeFalse()
        ->and(MediaAsset::withTrashed()->findOrFail($oldPhoto->id)->trashed())->toBeTrue();
});

it('keeps the batches through a re-run that reads the photo the same way, and not through one that reads it differently', function () {
    $plan = studioPlanWithStoredPhoto();
    Http::fakeSequence('api.openai.com/*')
        ->push(studioImageDescriptionBody())
        ->push(studioPostTextsBody())
        ->push(studioImageDescriptionBody())
        ->push(studioImageDescriptionBody(['summary' => 'A brown dog stands at the edge of the sea.']));
    $analysis = requestStudioImageAnalysis($this->admin, $plan);
    runStudioImageAnalysisJob($analysis);
    $batch = requestStudioPostTexts($this->admin, $plan->refresh());
    runStudioPostTextsJob($batch);

    $analysis = requestStudioImageAnalysis($this->admin, $plan, replacing: $analysis->fresh()->attempt_id);
    // While the re-run is on its way the batch is still the photo's.
    expect(isCurrentBatch($batch))->toBeTrue();
    runStudioImageAnalysisJob($analysis);

    expect(isCurrentBatch($batch))->toBeTrue();

    runStudioImageAnalysisJob(requestStudioImageAnalysis($this->admin, $plan, replacing: $analysis->fresh()->attempt_id));

    expect(isCurrentBatch($batch))->toBeFalse()
        ->and(StudioPlanTextGeneration::query()->count())->toBe(1);
});

it('keeps the batch current through a save of the texts, and never stands in that save\'s way', function () {
    $plan = studioPlanWithFinishedAnalysis(['title' => 'Before']);
    // The version the editor was drawn from, before anything was written.
    $drawn = StudioPlan::query()->findOrFail($plan->id)->lock_version;

    Http::fake(['api.openai.com/*' => Http::response(studioPostTextsBody())]);
    $batch = requestStudioPostTexts($this->admin, $plan);
    runStudioPostTextsJob($batch);

    app(UpdateStudioPlanAction::class)->handle($this->admin, $plan, new StudioPlanData(title: 'Nobody told him it was cloudy'), $drawn);

    expect(StudioPlan::query()->findOrFail($plan->id))->title->toBe('Nobody told him it was cloudy')->lock_version->toBe($drawn + 1)
        ->and(isCurrentBatch($batch))->toBeTrue();
});

it('deletes the batches with the Draft, and a job that comes late finds nothing to do', function () {
    $plan = studioPlanWithFinishedAnalysis();
    StudioPlanTextGeneration::factory()->forPlan($plan)->completed()->create();
    $queued = requestStudioPostTexts($this->admin, $plan);

    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $plan, StudioPlan::query()->findOrFail($plan->id)->lock_version);
    Http::fake();
    runStudioPostTextsJob($queued);

    expect(StudioPlanTextGeneration::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});
