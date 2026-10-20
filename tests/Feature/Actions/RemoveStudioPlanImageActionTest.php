<?php

use App\Actions\Studio\RemoveStudioPlanImageAction;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\User;

/**
 * Taking the photo off a Draft drops the plan's reference and, in the same
 * transaction, releases the photo through the media lifecycle — unless
 * something else still uses it. Nothing else is touched.
 */
it('takes the photo off, releasing it to the media lifecycle', function () {
    $persona = User::factory()->persona()->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->withImage()->create();
    $assetId = $plan->image_asset_id;

    app(RemoveStudioPlanImageAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect($plan->fresh())->image_asset_id->toBeNull()->lock_version->toBe(2)->author_user_id->toBe($persona->id)
        // Soft-deleted only: the files wait for the grace period.
        ->and(MediaAsset::withTrashed()->find($assetId)->trashed())->toBeTrue()
        ->and($plan)->image_asset_id->toBeNull()
        ->and($persona->fresh()->isPersona())->toBeTrue();
});

it('keeps a photo something else still uses', function (Closure $alsoUses) {
    $plan = StudioPlan::factory()->withImage()->create();
    $assetId = $plan->image_asset_id;
    $other = $alsoUses($assetId);

    app(RemoveStudioPlanImageAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect(MediaAsset::query()->find($assetId))->not->toBeNull()
        ->and($other->fresh()->image_asset_id)->toBe($assetId)
        ->and($plan->fresh()->image_asset_id)->toBeNull();
})->with([
    'another plan' => [fn (int $assetId) => StudioPlan::factory()->create(['image_asset_id' => $assetId])],
    'a published post' => [fn (int $assetId) => Post::factory()->published()->create(['image_asset_id' => $assetId])],
]);

it('leaves a plan with no photo as it is', function () {
    $plan = StudioPlan::factory()->create();

    app(RemoveStudioPlanImageAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect($plan->fresh()->lock_version)->toBe(1);
});

it('keeps the photo when the reference cannot be dropped and released together', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $assetId = $plan->image_asset_id;

    failingMediaReleases();

    expect(fn () => app(RemoveStudioPlanImageAction::class)->handle(User::factory()->admin()->create(), $plan, 1))
        ->toThrow(RuntimeException::class, 'Simulated release failure.');

    expect($plan->fresh())->image_asset_id->toBe($assetId)->lock_version->toBe(1)
        ->and(MediaAsset::query()->find($assetId))->not->toBeNull();
});

it('takes no photo off a plan that is gone, past its draft or changed, nor for anyone but an active administrator', function (Closure $admin, Closure $plan, int $version) {
    $plan = $plan();
    $assetId = $plan->image_asset_id;

    expect(fn () => app(RemoveStudioPlanImageAction::class)->handle($admin(), $plan, $version))
        ->toThrow(CannotManageStudioPlanException::class);

    expect(MediaAsset::query()->find($assetId))->not->toBeNull();
})->with([
    'an approved plan' => [fn () => User::factory()->admin()->create(), fn () => StudioPlan::factory()->withImage()->status(StudioPlanStatus::Approved)->create(), 1],
    'an older version' => [fn () => User::factory()->admin()->create(), fn () => StudioPlan::factory()->withImage()->create(['lock_version' => 3]), 2],
    'a moderator' => [fn () => User::factory()->moderator()->create(), fn () => StudioPlan::factory()->withImage()->create(), 1],
    'a limited administrator' => [fn () => User::factory()->admin()->limited()->create(), fn () => StudioPlan::factory()->withImage()->create(), 1],
]);
