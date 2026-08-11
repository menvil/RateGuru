<?php

use App\Models\MediaAsset;
use App\Models\Post;
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

it('batches reference checks across posts and avatars in two queries, regardless of how many ids are checked', function () {
    $referencedByPost = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $referencedByPost->id]);

    $referencedByTrashedPost = MediaAsset::factory()->postImage()->create();
    $trashedPost = Post::factory()->published()->create(['image_asset_id' => $referencedByTrashedPost->id]);
    $trashedPost->delete();

    $referencedByAvatar = MediaAsset::factory()->avatar()->create();
    User::factory()->create(['avatar_asset_id' => $referencedByAvatar->id]);

    $unreferenced = MediaAsset::factory()->postImage()->create();

    $assetIds = collect([$referencedByPost->id, $referencedByTrashedPost->id, $referencedByAvatar->id, $unreferenced->id]);

    $this->expectsDatabaseQueryCount(2);

    $referenced = app(MediaReferenceChecker::class)->referencedAssetIds($assetIds);

    expect($referenced->has($referencedByPost->id))->toBeTrue()
        ->and($referenced->has($referencedByTrashedPost->id))->toBeTrue()
        ->and($referenced->has($referencedByAvatar->id))->toBeTrue()
        ->and($referenced->has($unreferenced->id))->toBeFalse();
});
