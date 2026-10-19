<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\RemoveStudioPlanImageAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioImageAnalysisStatus;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A plan's photo analysis through the plan's own life: its photo replaced or
 * taken off, its texts saved, the Draft deleted. The analysis follows the
 * photo it was made for and never holds it — the media lifecycle releases a
 * replaced photo as if no analysis named it — and the editor's saves and the
 * analysis never stand in each other's way.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();
    $this->admin = User::factory()->admin()->create();
});

function completedAnalysisOf(User $admin, StudioPlan $plan): StudioPlanImageAnalysis
{
    Http::fake(['api.openai.com/*' => Http::response(studioImageDescriptionBody())]);
    $analysis = requestStudioImageAnalysis($admin, $plan);
    runStudioImageAnalysisJob($analysis);

    return $analysis->refresh();
}

it('keeps one analysis row per plan, gone with the plan, naming its photo without holding it', function () {
    $foreignKeys = collect(Schema::getForeignKeys('studio_plan_image_analyses'));

    expect($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->first()['columns'])->toBe(['studio_plan_id'])
        ->and($foreignKeys->first()['foreign_table'])->toBe('studio_plans')
        ->and(strtolower((string) $foreignKeys->first()['on_delete']))->toBe('cascade');

    $unique = collect(Schema::getIndexes('studio_plan_image_analyses'))->filter(fn (array $index): bool => $index['unique'])->pluck('columns')->all();

    expect($unique)->toContain(['studio_plan_id'], ['attempt_id']);
});

it('deletes the analysis with the Draft, and a job that comes late finds nothing to do', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = requestStudioImageAnalysis($this->admin, $plan);

    app(DeleteStudioPlanDraftAction::class)->handle($this->admin, $plan, StudioPlan::query()->findOrFail($plan->id)->lock_version);
    Http::fake();
    runStudioImageAnalysisJob($analysis);

    expect(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('stops counting an analysis as current once its photo is replaced, and releases the old photo regardless', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = completedAnalysisOf($this->admin, $plan);
    $oldPhoto = MediaAsset::query()->findOrFail($plan->image_asset_id);

    expect(app(MediaReferenceChecker::class)->isReferenced($oldPhoto))->toBeTrue();

    app(SetStudioPlanImageAction::class)->handle($this->admin, $plan, UploadedFile::fake()->image('new.jpg', 64, 64), StudioPlan::query()->findOrFail($plan->id)->lock_version);

    $plan->refresh();
    expect($analysis->refresh()->isFor($plan->image_asset_id))->toBeFalse()
        ->and($analysis->source_image_asset_id)->toBe($oldPhoto->id)
        // Nothing but the photo moved: the analysis row is as it was.
        ->and($analysis->status)->toBe(StudioImageAnalysisStatus::Completed)
        // The analysis named the old photo; it held nothing.
        ->and(app(MediaReferenceChecker::class)->isReferenced($oldPhoto))->toBeFalse()
        ->and(MediaAsset::withTrashed()->findOrFail($oldPhoto->id)->trashed())->toBeTrue();
});

it('stops counting an analysis as current once the photo is taken off', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = completedAnalysisOf($this->admin, $plan);

    app(RemoveStudioPlanImageAction::class)->handle($this->admin, $plan, StudioPlan::query()->findOrFail($plan->id)->lock_version);

    expect($analysis->refresh()->isFor(StudioPlan::query()->findOrFail($plan->id)->image_asset_id))->toBeFalse()
        ->and(MediaAsset::withTrashed()->findOrFail($analysis->source_image_asset_id)->trashed())->toBeTrue();
});

it('keeps the analysis current through a save of the texts, and never stands in that save\'s way', function () {
    $plan = studioPlanWithStoredPhoto(['title' => 'Before']);
    // The version the editor was drawn from, before anything was analysed.
    $drawn = StudioPlan::query()->findOrFail($plan->id)->lock_version;

    $analysis = completedAnalysisOf($this->admin, $plan);

    app(UpdateStudioPlanAction::class)->handle($this->admin, $plan, new StudioPlanData(title: 'After'), $drawn);

    $plan->refresh();
    expect($plan->title)->toBe('After')
        ->and($plan->lock_version)->toBe($drawn + 1)
        ->and($analysis->refresh()->isFor($plan->image_asset_id))->toBeTrue()
        ->and($analysis->status)->toBe(StudioImageAnalysisStatus::Completed);
});

it('adds no reference that keeps a photo from being purged', function () {
    $plan = studioPlanWithStoredPhoto();
    $analysis = completedAnalysisOf($this->admin, $plan);
    StudioPlan::query()->whereKey($plan->id)->update(['image_asset_id' => null]);

    $photo = MediaAsset::query()->findOrFail($analysis->source_image_asset_id);

    expect(app(MediaReferenceChecker::class)->isReferenced($photo))->toBeFalse();
});
