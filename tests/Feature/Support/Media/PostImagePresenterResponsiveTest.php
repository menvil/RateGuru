<?php

use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Enums\PostImageContext;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * @param  array<string, array{0: int, 1: int}>  $variantDimensionsByName  keyed by MediaVariantName::value
 */
function postWithVariants(array $variantDimensionsByName): Post
{
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();

    foreach ($variantDimensionsByName as $name => $dimensions) {
        MediaVariant::factory()->named(MediaVariantName::from($name))->create([
            'media_asset_id' => $asset->id,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);
    }

    return Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');
}

it('returns null when the post has no image asset', function () {
    $post = Post::factory()->published()->create(['image_asset_id' => null])->load('imageAsset.variants');

    expect(app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed))->toBeNull();
});

it('returns null for a private image asset even when its variants are already loaded, instead of throwing', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'visibility' => MediaVisibility::Private,
    ]);
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create([
        'media_asset_id' => $asset->id,
        'width' => 640,
        'height' => 427,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    expect($post->imageAsset->relationLoaded('variants'))->toBeTrue();
    expect(app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed))->toBeNull();
});

it('falls back to the master image when no variants exist yet', function () {
    $post = postWithVariants([]);

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed);

    expect($image)->not->toBeNull()
        ->and($image->srcset)->toBeNull()
        ->and($image->sizes)->toBeNull()
        ->and($image->width)->toBe($post->imageAsset->width)
        ->and($image->height)->toBe($post->imageAsset->height);
});

it('prefers feed_640 as src and includes only feed_640/feed_1280 in the feed srcset', function () {
    $post = postWithVariants([
        MediaVariantName::PostFeed640->value => [640, 427],
        MediaVariantName::PostFeed1280->value => [1280, 853],
        MediaVariantName::PostDetail1920->value => [1920, 1280],
    ]);

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed);

    expect($image->width)->toBe(640)
        ->and($image->height)->toBe(427)
        ->and($image->srcset)->toContain('640w')
        ->and($image->srcset)->toContain('1280w')
        ->and($image->srcset)->not->toContain('1920w')
        ->and($image->sizes)->not->toBeNull();
});

it('falls back through the chain to a detail variant for feed when smaller ones are missing', function () {
    $post = postWithVariants([
        MediaVariantName::PostDetail1920->value => [1920, 1280],
    ]);

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed);

    // 1920 isn't in feed's srcset membership, but it's still preferred over
    // falling all the way back to the master for `src`.
    expect($image->width)->toBe(1920)
        ->and($image->srcset)->toBeNull()
        ->and($image->sizes)->toBeNull();
});

it('prefers feed_1280 for standalone and includes feed_1280/detail_1920 in its srcset', function () {
    $post = postWithVariants([
        MediaVariantName::PostFeed640->value => [640, 427],
        MediaVariantName::PostFeed1280->value => [1280, 853],
        MediaVariantName::PostDetail1920->value => [1920, 1280],
    ]);

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Standalone);

    expect($image->width)->toBe(1280)
        ->and($image->srcset)->not->toContain('640w')
        ->and($image->srcset)->toContain('1280w')
        ->and($image->srcset)->toContain('1920w');
});

it('prefers detail_1920 for fullscreen and includes the master when it is not drastically larger', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2000, 1333)->create();
    MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create([
        'media_asset_id' => $asset->id,
        'width' => 1920,
        'height' => 1280,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Fullscreen);

    expect($image->width)->toBe(1920)
        ->and($image->srcset)->toContain('1920w')
        ->and($image->srcset)->toContain('2000w');
});

it('excludes the master from the fullscreen srcset when it is drastically larger than detail_1920', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(6000, 4000)->create();
    MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create([
        'media_asset_id' => $asset->id,
        'width' => 1920,
        'height' => 1280,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Fullscreen);

    expect($image->srcset)->toContain('1920w')
        ->and($image->srcset)->not->toContain('6000w');
});
