<?php

use App\Enums\MediaKind;
use App\Enums\StudioPlanStatus;
use App\Models\StudioAction;
use App\Models\StudioPlan;

/*
 * A plan past its Draft is one approval made: a Persona author, a title and
 * an administrator who approved it. Its post's action is written by that
 * Persona, as ApproveStudioPlanAction makes it.
 */

it('makes a plan past its Draft as approval leaves it', function (StudioPlanStatus $status) {
    $plan = StudioPlan::factory()->status($status)->create();

    expect($plan->status)->toBe($status)
        ->and($plan->author?->isPersona())->toBeTrue()
        ->and($plan->title)->not->toBeNull()
        ->and($plan->approved_at)->not->toBeNull()
        ->and($plan->approvedBy?->isAdmin())->toBeTrue()
        // The photo approval asks for: a post image its creator uploaded.
        ->and($plan->imageAsset?->kind)->toBe(MediaKind::PostImage)
        ->and($plan->imageAsset?->owner_user_id)->toBe($plan->created_by_user_id);
})->with([StudioPlanStatus::Approved, StudioPlanStatus::Running, StudioPlanStatus::Completed, StudioPlanStatus::Cancelled]);

it('leaves a Draft as an administrator has just made it', function () {
    $plan = StudioPlan::factory()->status(StudioPlanStatus::Draft)->create();

    expect($plan->author_user_id)->toBeNull()
        ->and($plan->approved_by_user_id)->toBeNull();
});

it('has the plan\'s Persona write its post', function () {
    $action = StudioAction::factory()->create();

    expect($action->actor_user_id)->toBe($action->plan->author_user_id)
        ->and($action->plan->status)->toBe(StudioPlanStatus::Approved);
});
