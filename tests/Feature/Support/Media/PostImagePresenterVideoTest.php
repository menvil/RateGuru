<?php

use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Enums\PostImageContext;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Support\Media\PostImagePresenter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
});

/** A published video post whose asset is its MP4 master, with these poster variants. */
function videoPostWithPosterVariants(MediaVariantName ...$names): Post
{
    $asset = MediaAsset::factory()->postImage()->dimensions(1080, 1920)->create([
        'media_type' => MediaType::Video,
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path' => 'media/post-videos/2026/11/'.Str::uuid().'.mp4',
    ]);
    Storage::disk('public')->put($asset->path, 'mp4');

    foreach ($names as $name) {
        $variant = MediaVariant::factory()->named($name)->create(['media_asset_id' => $asset->id, 'width' => 640, 'height' => 1138]);
        Storage::disk('public')->put($variant->path, 'webp');
    }

    return Post::factory()->published()->create(['image_asset_id' => $asset->id])->load('imageAsset.variants');
}

it('shows a video post by its poster and never offers the MP4 as a picture', function () {
    $post = videoPostWithPosterVariants(MediaVariantName::PostFeed640, MediaVariantName::PostFeed1280, MediaVariantName::PostDetail1920, MediaVariantName::OpenGraph);
    $presenter = app(PostImagePresenter::class);
    $mp4 = Storage::disk('public')->url($post->imageAsset->path);

    foreach (PostImageContext::cases() as $context) {
        $image = $presenter->responsive($post, $context);

        expect($image)->not->toBeNull()
            ->and($image->src)->not->toBe($mp4)
            ->and((string) $image->srcset)->not->toContain($mp4);
    }

    expect($presenter->openGraph($post)?->url)->toBe(variantUrl($post, MediaVariantName::OpenGraph))
        ->and($presenter->shareUrl($post))->toBe(variantUrl($post, MediaVariantName::PostDetail1920))
        ->and($presenter->url($post))->toBe(variantUrl($post, MediaVariantName::PostDetail1920))
        ->and($presenter->thumbnailUrl($post))->toBe(variantUrl($post, MediaVariantName::PostFeed640));
});

it('has no picture for a video post whose poster variants are missing or were not loaded', function () {
    $presenter = app(PostImagePresenter::class);
    $bare = videoPostWithPosterVariants();

    expect($presenter->responsive($bare, PostImageContext::Feed))->toBeNull()
        ->and($presenter->openGraph($bare))->toBeNull()
        ->and($presenter->shareUrl($bare))->toBeNull()
        ->and($presenter->thumbnailUrl($bare))->toBeNull();

    $unloaded = Post::query()->with('imageAsset')->findOrFail(videoPostWithPosterVariants(MediaVariantName::PostFeed640)->id);

    expect($presenter->responsive($unloaded, PostImageContext::Feed))->toBeNull()
        ->and($presenter->url($unloaded))->toBeNull();
});
