<?php

use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The picture a share hands to another app as a file: the whole picture, at
 * the detail size rather than the original upload.
 */
beforeEach(function () {
    Storage::fake('public');
});

function sharedPictureOf(Post $post): ?string
{
    return app(PostImagePresenter::class)->shareUrl($post);
}

it('shares the detail-sized picture', function () {
    $post = storeVariantFiles(postWithVariants([
        MediaVariantName::OpenGraph->value => [1200, 630],
        MediaVariantName::PostDetail1920->value => [1920, 1280],
        MediaVariantName::PostFeed640->value => [640, 427],
    ]), MediaVariantName::OpenGraph, MediaVariantName::PostDetail1920, MediaVariantName::PostFeed640);

    expect(sharedPictureOf($post))->toBe(variantUrl($post, MediaVariantName::PostDetail1920));
});

it('shares the original when the detail size was not generated or its file is gone', function () {
    $post = storeVariantFiles(postWithVariants([
        MediaVariantName::OpenGraph->value => [1200, 630],
        MediaVariantName::PostFeed1280->value => [1280, 853],
    ]), MediaVariantName::OpenGraph, MediaVariantName::PostFeed1280);

    expect(sharedPictureOf($post))->toBe(Storage::disk('public')->url($post->imageAsset->path));

    $stale = postWithVariants([MediaVariantName::PostDetail1920->value => [1920, 1280]]);

    expect(sharedPictureOf($stale))->toBe(Storage::disk('public')->url($stale->imageAsset->path));
});

it('never lazy-loads the variants', function () {
    $post = postWithVariants([MediaVariantName::PostDetail1920->value => [1920, 1280]]);
    $post = Post::query()->with('imageAsset')->findOrFail($post->id);

    DB::enableQueryLog();
    $url = sharedPictureOf($post);

    expect($url)->toBe(Storage::disk('public')->url($post->imageAsset->path))
        ->and(DB::getQueryLog())->toBe([]);
});

it('has no picture to share for a post without an image or with a private one', function () {
    $asset = MediaAsset::factory()->postImage()->create(['visibility' => MediaVisibility::Private]);
    MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create(['media_asset_id' => $asset->id]);
    $private = Post::factory()->published()->create(['image_asset_id' => $asset->id])->load('imageAsset.variants');
    $none = Post::factory()->published()->create(['image_asset_id' => null])->load('imageAsset.variants');

    expect(sharedPictureOf($private))->toBeNull()
        ->and(sharedPictureOf($none))->toBeNull();
});
