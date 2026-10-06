<?php

use App\Enums\MediaVariantName;
use App\Enums\PostImageContext;
use App\Http\Resources\Api\PostResource;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use Illuminate\Http\Request;

/**
 * `media_assets.width` and `.height` are nullable columns, so an asset stored
 * before dimensions were recorded — or one whose probe failed — has neither.
 * `ResponsiveImage` declared them `int`, which did not make the data non-null: it
 * made every construction site throw a TypeError on such an asset.
 *
 * Six sites were affected, across the post presenter and the avatar resolver —
 * four of them the master-fallback DTO — and the reachable consequence was API
 * serialisation of an ordinary public post.
 */
function postWithDimensionlessImage(): Post
{
    $post = Post::factory()->create();

    // dimensionless(), not width/height overridden by hand: aspect_ratio and
    // orientation are computed from the dimensions, so a fixture that nulls only
    // the first two models a row the application cannot produce.
    $asset = MediaAsset::factory()->dimensionless()->create();

    $post->forceFill(['image_asset_id' => $asset->id])->save();

    // Deliberately NOT eager-loading variants: that is the branch which passes
    // the asset's own dimensions straight into the DTO, and the presenter never
    // lazy-loads, so this is the shape a real request produces.
    return $post->fresh(['imageAsset']);
}

it('presents an image whose dimensions were never recorded', function () {
    $post = postWithDimensionlessImage();

    $image = app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed);

    expect($image)->not->toBeNull();
    expect($image->width)->toBeNull();
    expect($image->height)->toBeNull();

    // The src still resolves — a missing dimension is a missing attribute, not a
    // missing image.
    expect($image->src)->not->toBeEmpty();
});

it('serialises a post whose image has no dimensions', function () {
    // The failure as an API consumer would have met it: a TypeError, on a public
    // post, from a GET.
    $post = postWithDimensionlessImage();

    $payload = (new PostResource($post))->toArray(Request::create('/'));

    expect($payload['image_width'])->toBeNull();
    expect($payload['image_height'])->toBeNull();
    expect($payload['image_url'])->not->toBeEmpty();
});

it('still reports dimensions when the asset has them', function () {
    // The other half: nullable must not mean "always null". An asset that knows
    // its size still says so.
    $post = Post::factory()->create();
    $asset = MediaAsset::factory()->create(['width' => 1600, 'height' => 900]);
    $post->forceFill(['image_asset_id' => $asset->id])->save();

    $image = app(PostImagePresenter::class)->responsive($post->fresh(['imageAsset']), PostImageContext::Feed);

    expect($image->width)->toBe(1600);
    expect($image->height)->toBe(900);
});

it('offers no srcset descriptor it cannot compute for a dimensionless asset', function () {
    // The fullscreen context weighs the master against the detail variant before
    // offering it. PHP coerces a null width to 0 in that comparison, so an asset
    // with no recorded dimensions passed the test and contributed an entry with no
    // descriptor at all — "https://… w" — which is a malformed srcset.
    $post = Post::factory()->create();
    $asset = MediaAsset::factory()->dimensionless()->create();
    $post->forceFill(['image_asset_id' => $asset->id])->save();

    MediaVariant::factory()->for($asset, 'asset')->create([
        'name' => MediaVariantName::PostDetail1920,
        'width' => 1920,
        'height' => 1080,
    ]);

    $image = app(PostImagePresenter::class)->responsive(
        $post->fresh(['imageAsset.variants']),
        PostImageContext::Fullscreen,
    );

    expect($image)->not->toBeNull();
    expect($image->srcset ?? '')->not->toContain(' w,');
    expect(preg_match('/\s w(,|$)/', (string) ($image->srcset ?? '')))
        ->toBe(0, "a srcset descriptor must always carry a width: {$image->srcset}");
});
