<?php

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;

it('reports an asset as referenced when a published post uses it as its image', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeTrue();
});

it('reports an asset as referenced when a soft-deleted, restorable post still points at it', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    $post->delete();

    expect($post->trashed())->toBeTrue()
        ->and(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeTrue();
});

it('reports an asset as referenced when a user uses it as their avatar', function () {
    $asset = MediaAsset::factory()->avatar()->create();
    User::factory()->create(['avatar_asset_id' => $asset->id]);

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeTrue();
});

it('reports an asset as unreferenced when nothing points at it', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeFalse();
});

it('does not treat one post pointing at a different asset as a reference', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $otherAsset = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $otherAsset->id]);

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeFalse();
});

it('does not treat one user\'s avatar pointing at a different asset as a reference', function () {
    $asset = MediaAsset::factory()->avatar()->create();
    $otherAsset = MediaAsset::factory()->avatar()->create();
    User::factory()->create(['avatar_asset_id' => $otherAsset->id]);

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeFalse();
});

it('reports an asset as referenced when a publication plan uses it as its photo, and nothing else does', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    StudioPlan::factory()->create(['image_asset_id' => $asset->id]);

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeTrue()
        ->and(app(MediaReferenceChecker::class)->referencedAssetIds(collect([$asset->id]))->has($asset->id))->toBeTrue();
});

it('reports an asset as referenced whichever of several sources still uses it', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $plan = StudioPlan::factory()->create(['image_asset_id' => $asset->id]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    User::factory()->create(['avatar_asset_id' => $asset->id]);
    $checker = app(MediaReferenceChecker::class);

    expect($checker->isReferenced($asset))->toBeTrue();

    $plan->forceFill(['image_asset_id' => null])->save();
    $post->forceFill(['image_asset_id' => null])->save();

    // The avatar alone still holds it.
    expect($checker->isReferenced($asset))->toBeTrue()
        ->and($checker->referencedAssetIds(collect([$asset->id]))->has($asset->id))->toBeTrue();
});

it('reports an asset as unreferenced once the last plan lets it go', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $first = StudioPlan::factory()->create(['image_asset_id' => $asset->id]);
    $second = StudioPlan::factory()->create(['image_asset_id' => $asset->id]);
    $checker = app(MediaReferenceChecker::class);

    $first->delete();

    expect($checker->isReferenced($asset))->toBeTrue();

    $second->forceFill(['image_asset_id' => null])->save();

    expect($checker->isReferenced($asset))->toBeFalse()
        ->and($checker->referencedAssetIds(collect([$asset->id])))->toBeEmpty();
});

it('does not treat a plan pointing at a different asset as a reference', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    StudioPlan::factory()->withImage()->create();

    expect(app(MediaReferenceChecker::class)->isReferenced($asset))->toBeFalse();
});

it('batches reference checks across posts, avatars and plans in three queries, regardless of how many ids are checked', function () {
    $referencedByPost = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $referencedByPost->id]);

    $referencedByTrashedPost = MediaAsset::factory()->postImage()->create();
    $trashedPost = Post::factory()->published()->create(['image_asset_id' => $referencedByTrashedPost->id]);
    $trashedPost->delete();

    $referencedByAvatar = MediaAsset::factory()->avatar()->create();
    User::factory()->create(['avatar_asset_id' => $referencedByAvatar->id]);

    $referencedByPlans = MediaAsset::factory()->postImage()->count(30)->create();
    $referencedByPlans->each(fn (MediaAsset $asset) => StudioPlan::factory()->create(['image_asset_id' => $asset->id]));

    $unreferenced = MediaAsset::factory()->postImage()->count(20)->create();

    $assetIds = collect([$referencedByPost->id, $referencedByTrashedPost->id, $referencedByAvatar->id])
        ->merge($referencedByPlans->modelKeys())
        ->merge($unreferenced->modelKeys());

    $this->expectsDatabaseQueryCount(3);

    $referenced = app(MediaReferenceChecker::class)->referencedAssetIds($assetIds);

    expect($referenced->has($referencedByPost->id))->toBeTrue()
        ->and($referenced->has($referencedByTrashedPost->id))->toBeTrue()
        ->and($referenced->has($referencedByAvatar->id))->toBeTrue()
        ->and($referencedByPlans->every(fn (MediaAsset $asset): bool => $referenced->has($asset->id)))->toBeTrue()
        ->and($unreferenced->contains(fn (MediaAsset $asset): bool => $referenced->has($asset->id)))->toBeFalse();
});

it('asks nothing of the database for no ids at all', function () {
    $this->expectsDatabaseQueryCount(0);

    expect(app(MediaReferenceChecker::class)->referencedAssetIds(collect()))->toBeEmpty();
});
