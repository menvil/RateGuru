<?php

use App\Models\MediaAsset;
use App\Models\Post;

it('belongs to an image asset', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $post = Post::factory()->create(['image_asset_id' => $asset->id]);

    expect($post->imageAsset)->toBeInstanceOf(MediaAsset::class)
        ->and($post->imageAsset->id)->toBe($asset->id);
});

it('has no image asset by default', function () {
    $post = Post::factory()->create(['image_asset_id' => null]);

    expect($post->imageAsset)->toBeNull();
});

it('nulls the image asset id when the referenced asset is deleted', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    $post = Post::factory()->create(['image_asset_id' => $asset->id]);

    $asset->forceDelete();

    expect($post->fresh()->image_asset_id)->toBeNull();
});
