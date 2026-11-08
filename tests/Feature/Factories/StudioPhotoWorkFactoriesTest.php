<?php

use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanTextGeneration;

/*
 * The work done on a plan's photo — its analysis, its texts, its discussion —
 * is made for a plan with a photo. Asked for a plan without one, the factory
 * says so instead of pointing the work at a photo numbered 0.
 */

it('refuses photo work for a plan without a photo', function (string $model) {
    $plan = StudioPlan::factory()->create(['image_asset_id' => null]);

    expect(fn () => $model::factory()->create(['studio_plan_id' => $plan->id]))
        ->toThrow(LogicException::class, 'A plan without a photo has nothing to');
})->with([
    'an analysis' => [StudioPlanImageAnalysis::class],
    'a text generation' => [StudioPlanTextGeneration::class],
    'a discussion generation' => [StudioPlanDiscussionGeneration::class],
]);

it('refuses photo work for a plan without a photo when asked for that plan', function (string $model) {
    $plan = StudioPlan::factory()->create(['image_asset_id' => null]);

    expect(fn () => $model::factory()->forPlan($plan)->make())
        ->toThrow(LogicException::class, 'A plan without a photo has nothing to');
})->with([
    'an analysis' => [StudioPlanImageAnalysis::class],
    'a text generation' => [StudioPlanTextGeneration::class],
]);

it('still makes photo work for a plan with a photo', function (string $model) {
    $plan = StudioPlan::factory()->withImage()->create();

    expect($model::factory()->create(['studio_plan_id' => $plan->id])->source_image_asset_id)->toBe($plan->image_asset_id);
})->with([
    'an analysis' => [StudioPlanImageAnalysis::class],
    'a text generation' => [StudioPlanTextGeneration::class],
    'a discussion generation' => [StudioPlanDiscussionGeneration::class],
]);
