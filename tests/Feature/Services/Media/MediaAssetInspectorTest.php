<?php

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaAssetInspector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports master existence, reference state, and variant existence for a healthy asset', function () {
    $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    $variant = MediaVariant::factory()->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'media/post-images/a/variant.jpg',
    ]);
    Storage::disk('public')->put($variant->path, 'variant-bytes');
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $inspection = app(MediaAssetInspector::class)->inspect($asset);

    expect($inspection->masterExists)->toBeTrue()
        ->and($inspection->referenced)->toBeTrue()
        ->and($inspection->variants)->toHaveCount(1)
        ->and($inspection->variants->first()['exists'])->toBeTrue()
        ->and($inspection->referencingPosts->pluck('id')->all())->toBe([$post->id])
        ->and($inspection->referencingUsers)->toBeEmpty();
});

it('reports a missing master and missing variant file as not existing', function () {
    $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'media/post-images/missing.jpg']);
    $variant = MediaVariant::factory()->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'media/post-images/missing/variant.jpg',
    ]);

    $inspection = app(MediaAssetInspector::class)->inspect($asset);

    expect($inspection->masterExists)->toBeFalse()
        ->and($inspection->variants->first()['exists'])->toBeFalse();
});

it('reports the referencing user for an avatar asset', function () {
    $asset = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $asset->id]);

    $inspection = app(MediaAssetInspector::class)->inspect($asset);

    expect($inspection->referenced)->toBeTrue()
        ->and($inspection->referencingUsers->pluck('id')->all())->toBe([$user->id])
        ->and($inspection->referencingPosts)->toBeEmpty();
});

it('reports lifecycle state for an active asset with no purgeable-at date', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    $inspection = app(MediaAssetInspector::class)->inspect($asset);

    expect($inspection->asset->trashed())->toBeFalse()
        ->and($inspection->purgeableAt)->toBeNull()
        ->and($inspection->graceExpired)->toBeFalse()
        ->and($inspection->purgeable)->toBeFalse();
});

it('reports lifecycle state and a computed purgeable-at date for a purgeable asset', function () {
    $asset = createPurgeableAsset();

    $inspection = app(MediaAssetInspector::class)->inspect($asset);

    expect($inspection->asset->trashed())->toBeTrue()
        ->and($inspection->purgeableAt)->not->toBeNull()
        ->and($inspection->purgeableAt->equalTo($asset->deleted_at->clone()->addDays((int) config('media.lifecycle.purge_grace_days'))))->toBeTrue()
        ->and($inspection->graceExpired)->toBeTrue()
        ->and($inspection->purgeable)->toBeTrue();
});

it('reports grace-not-expired for a soft-deleted asset still within its grace period', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00'));

    $inspection = app(MediaAssetInspector::class)->inspect($asset->fresh());

    expect($inspection->graceExpired)->toBeFalse()
        ->and($inspection->purgeable)->toBeFalse();
});
