<?php

use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * openGraph() checks physical file existence for every candidate (unlike
 * responsive()) — a stale DB row pointing at a deleted file must never leak
 * as an og:image URL a crawler would 404 on. Tests that expect a candidate
 * to actually be chosen must write real bytes at that candidate's own
 * (disk, path); tests that don't call this leave the row deliberately
 * stale, to exercise the fallback.
 */
function putMediaBytes(string $disk, string $path): void
{
    Storage::disk($disk)->put($path, 'test-bytes');
}

it('returns null when the post has no image asset', function () {
    $post = Post::factory()->published()->create(['image_asset_id' => null])->load('imageAsset.variants');

    expect(app(PostImagePresenter::class)->openGraph($post))->toBeNull();
});

it('returns null for a private image asset even when its variants are already loaded, instead of leaking a private url', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'visibility' => MediaVisibility::Private,
    ]);
    MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'width' => 1200,
        'height' => 630,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    expect($post->imageAsset->relationLoaded('variants'))->toBeTrue();
    expect(app(PostImagePresenter::class)->openGraph($post))->toBeNull();
});

it('falls back to the master image and its own dimensions when no open graph or detail variant exists yet', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(1600, 900)->create();
    putMediaBytes($asset->disk, $asset->path);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->openGraph($post);

    expect($image)->not->toBeNull()
        ->and($image->width)->toBe(1600)
        ->and($image->height)->toBe(900)
        ->and($image->mimeType)->toBe($asset->mime_type);
});

it('falls back to the master image when imageAsset.variants is not eager-loaded, never lazy-loading', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(1600, 900)->create();
    putMediaBytes($asset->disk, $asset->path);
    MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'width' => 1200,
        'height' => 630,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset'); // imageAsset loaded, imageAsset.variants deliberately not

    expect($post->imageAsset->relationLoaded('variants'))->toBeFalse();

    $image = app(PostImagePresenter::class)->openGraph($post);

    expect($image->width)->toBe(1600)
        ->and($image->height)->toBe(900);
});

it('prefers the dedicated open graph variant over post_detail_1920 and the master', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();
    $detail = MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create([
        'media_asset_id' => $asset->id,
        'width' => 1920,
        'height' => 1280,
        'mime_type' => 'image/jpeg',
    ]);
    $openGraph = MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'width' => 1200,
        'height' => 630,
        'mime_type' => 'image/jpeg',
    ]);
    putMediaBytes($detail->disk, $detail->path);
    putMediaBytes($openGraph->disk, $openGraph->path);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->openGraph($post);

    expect($image->width)->toBe(1200)
        ->and($image->height)->toBe(630)
        ->and($image->mimeType)->toBe('image/jpeg');
});

it('falls back to post_detail_1920 when the open graph variant does not exist yet', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();
    $detail = MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create([
        'media_asset_id' => $asset->id,
        'width' => 1920,
        'height' => 1280,
    ]);
    putMediaBytes($detail->disk, $detail->path);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->openGraph($post);

    expect($image->width)->toBe(1920)
        ->and($image->height)->toBe(1280);
});

it('never falls back to a feed-sized variant, even when it is the only variant available', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();
    putMediaBytes($asset->disk, $asset->path);
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create([
        'media_asset_id' => $asset->id,
        'width' => 640,
        'height' => 427,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->openGraph($post);

    // feed_640 exists but is never eligible for OG — falls all the way back
    // to the master's own dimensions instead.
    expect($image->width)->toBe($asset->width)
        ->and($image->height)->toBe($asset->height);
});

it('skips an open graph variant whose row exists but whose physical file is gone, falling through to a still-existing post_detail_1920', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();
    // Open graph row exists, but no bytes are ever written for it —
    // simulates a lost/wiped physical file with a still-present row.
    MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'width' => 1200,
        'height' => 630,
    ]);
    $detail = MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create([
        'media_asset_id' => $asset->id,
        'width' => 1920,
        'height' => 1280,
    ]);
    putMediaBytes($detail->disk, $detail->path);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    $image = app(PostImagePresenter::class)->openGraph($post);

    expect($image->width)->toBe(1920)
        ->and($image->height)->toBe(1280);
});

it('returns null when every candidate record points at a physical file that no longer exists', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();
    // Neither the open graph variant nor the master itself has any bytes
    // written — every candidate is a stale record.
    MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'width' => 1200,
        'height' => 630,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');

    expect(app(PostImagePresenter::class)->openGraph($post))->toBeNull();
});
