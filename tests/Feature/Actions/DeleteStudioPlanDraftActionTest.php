<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A Draft is deleted outright — it has no history yet — and its photo is
 * released to the media lifecycle in the same transaction, unless something
 * else still uses it. A plan past its Draft is never deleted, and a second
 * delete releases nothing again.
 */
afterEach(function () {
    Carbon::setTestNow();
});

it('deletes the draft and releases its photo, keeping the file through the grace period', function () {
    Storage::fake('public');
    $plan = StudioPlan::factory()->withImage()->create();
    $asset = $plan->imageAsset;
    Storage::disk('public')->put($asset->path, 'photo');

    app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect(StudioPlan::query()->find($plan->id))->toBeNull()
        ->and($plan->exists)->toBeFalse()
        ->and(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull()
        ->and(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($asset->path);
});

it('deletes a draft with no photo', function () {
    $plan = StudioPlan::factory()->create();

    app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect(StudioPlan::query()->exists())->toBeFalse();
});

it('keeps a photo something else still uses, and that something with it', function (Closure $alsoUses) {
    $plan = StudioPlan::factory()->withImage()->create();
    $assetId = $plan->image_asset_id;
    $other = $alsoUses($assetId);

    app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect(MediaAsset::query()->find($assetId))->not->toBeNull()
        ->and($other->fresh())->not->toBeNull()
        ->and($other->fresh()->image_asset_id)->toBe($assetId);
})->with([
    'another plan' => [fn (int $assetId) => StudioPlan::factory()->create(['image_asset_id' => $assetId])],
    'a published post' => [fn (int $assetId) => Post::factory()->published()->create(['image_asset_id' => $assetId])],
]);

it('leaves the author and every post as they were', function () {
    $persona = User::factory()->persona()->create();
    $post = Post::factory()->published()->for($persona)->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->withImage()->create();

    app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1);

    expect($persona->fresh())->isPersona()->toBeTrue()->isTombstoned()->toBeFalse()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue();
});

it('deletes no plan past its draft', function (StudioPlanStatus $status) {
    $plan = StudioPlan::factory()->withImage()->status($status)->create();

    expect(fn () => app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isNotDraft())->toBeTrue());

    expect(StudioPlan::query()->find($plan->id))->not->toBeNull()
        ->and(MediaAsset::query()->find($plan->image_asset_id))->not->toBeNull();
})->with([StudioPlanStatus::Approved, StudioPlanStatus::Running, StudioPlanStatus::Completed, StudioPlanStatus::Cancelled]);

it('refuses to delete a draft changed since it was seen', function () {
    $plan = StudioPlan::factory()->create(['lock_version' => 2]);

    expect(fn () => app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isStale())->toBeTrue());

    expect(StudioPlan::query()->find($plan->id))->not->toBeNull();
});

it('says a second delete found nothing, and releases nothing again', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->withImage()->create();
    $assetId = $plan->image_asset_id;

    app(DeleteStudioPlanDraftAction::class)->handle($admin, $plan, 1);
    $released = MediaAsset::withTrashed()->findOrFail($assetId)->deleted_at;

    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

    expect(fn () => app(DeleteStudioPlanDraftAction::class)->handle($admin, $plan, 1))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isGone())->toBeTrue());

    // The grace period runs from the first release, not from the second request.
    expect(MediaAsset::withTrashed()->findOrFail($assetId)->deleted_at->equalTo($released))->toBeTrue();
});

it('keeps the draft and its photo when the release fails', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $assetId = $plan->image_asset_id;

    failingMediaReleases();

    expect(fn () => app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), $plan, 1))
        ->toThrow(RuntimeException::class, 'Simulated release failure.');

    expect(StudioPlan::query()->find($plan->id))->not->toBeNull()
        ->and($plan->fresh()->image_asset_id)->toBe($assetId)
        ->and(MediaAsset::query()->find($assetId))->not->toBeNull();
});

it('lets nobody but an active administrator delete a draft', function (Closure $user) {
    $plan = StudioPlan::factory()->create();

    expect(fn () => app(DeleteStudioPlanDraftAction::class)->handle($user(), $plan, 1))
        ->toThrow(CannotManageStudioPlanException::class);

    expect(StudioPlan::query()->find($plan->id))->not->toBeNull();
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
    'a persona' => [fn () => User::factory()->persona()->create()],
    'a shadowbanned administrator' => [fn () => User::factory()->admin()->shadowbanned()->create()],
]);
