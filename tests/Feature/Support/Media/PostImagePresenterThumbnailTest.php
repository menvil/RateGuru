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
 * The thumbnail a list of posts shows: the smallest generated variant, never
 * the original upload when a variant exists.
 */
beforeEach(function () {
    Storage::fake('public');
});

function thumbnailOf(Post $post): ?string
{
    return app(PostImagePresenter::class)->thumbnailUrl($post);
}

function variantUrl(Post $post, MediaVariantName $name): string
{
    return Storage::disk('public')->url($post->imageAsset->variants->firstWhere('name', $name)->path);
}

/** Puts the files of these variants on disk; a variant row without its file is stale. */
function storeVariantFiles(Post $post, MediaVariantName ...$names): Post
{
    foreach ($names as $name) {
        Storage::disk('public')->put($post->imageAsset->variants->firstWhere('name', $name)->path, 'image');
    }

    return $post;
}

it('uses the smallest generated variant', function () {
    $post = storeVariantFiles(postWithVariants([
        MediaVariantName::PostDetail1920->value => [1920, 1280],
        MediaVariantName::PostFeed1280->value => [1280, 853],
        MediaVariantName::PostFeed640->value => [640, 427],
    ]), MediaVariantName::PostDetail1920, MediaVariantName::PostFeed1280, MediaVariantName::PostFeed640);

    expect(thumbnailOf($post))->toBe(variantUrl($post, MediaVariantName::PostFeed640));
});

it('takes the next smallest variant when the smallest was not generated', function () {
    $post = storeVariantFiles(postWithVariants([
        MediaVariantName::PostDetail1920->value => [1920, 1280],
        MediaVariantName::PostFeed1280->value => [1280, 853],
    ]), MediaVariantName::PostDetail1920, MediaVariantName::PostFeed1280);

    expect(thumbnailOf($post))->toBe(variantUrl($post, MediaVariantName::PostFeed1280));
});

it('passes over a variant whose file is gone, to the next one or the original', function () {
    $post = storeVariantFiles(postWithVariants([
        MediaVariantName::PostFeed1280->value => [1280, 853],
        MediaVariantName::PostFeed640->value => [640, 427],
    ]), MediaVariantName::PostFeed1280);

    expect(thumbnailOf($post))->toBe(variantUrl($post, MediaVariantName::PostFeed1280));

    Storage::disk('public')->delete($post->imageAsset->variants->firstWhere('name', MediaVariantName::PostFeed1280)->path);

    expect(thumbnailOf($post))->toBe(Storage::disk('public')->url($post->imageAsset->path));
});

it('falls back to the original when no variant exists yet', function () {
    $post = postWithVariants([]);

    expect(thumbnailOf($post))->toBe(Storage::disk('public')->url($post->imageAsset->path));
});

it('never lazy-loads the variants', function () {
    $post = postWithVariants([MediaVariantName::PostFeed640->value => [640, 427]]);
    $post = Post::query()->with('imageAsset')->findOrFail($post->id);

    DB::enableQueryLog();
    $url = thumbnailOf($post);

    expect($url)->toBe(Storage::disk('public')->url($post->imageAsset->path))
        ->and(DB::getQueryLog())->toBe([]);
});

it('has no thumbnail for a post without an image or with a private one', function () {
    $asset = MediaAsset::factory()->postImage()->create(['visibility' => MediaVisibility::Private]);
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $asset->id]);
    $private = Post::factory()->published()->create(['image_asset_id' => $asset->id])->load('imageAsset.variants');
    $none = Post::factory()->published()->create(['image_asset_id' => null])->load('imageAsset.variants');

    expect(thumbnailOf($private))->toBeNull()
        ->and(thumbnailOf($none))->toBeNull();
});
