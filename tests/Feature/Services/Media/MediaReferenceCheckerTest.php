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
