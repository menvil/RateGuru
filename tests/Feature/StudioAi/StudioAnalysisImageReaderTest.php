<?php

use App\Enums\MediaVariantName;
use App\Enums\StudioImageAnalysisError;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\StudioPlan;
use App\Support\StudioAi\StudioAnalysisImageReader;
use App\Support\StudioAi\StudioImageAnalysisFailedException;
use Illuminate\Support\Facades\Storage;

/**
 * What analysis reads of a stored photo: the ~1280 px feed variant when
 * there is one that can be read, the normalized master otherwise — through
 * the media library's own disk and path, within a byte limit, and only when
 * the bytes are an image OpenAI takes. Nothing is written anywhere.
 */
beforeEach(function () {
    Storage::fake('public');
});

function readFailure(int $assetId): StudioImageAnalysisError
{
    try {
        app(StudioAnalysisImageReader::class)->read($assetId);
    } catch (StudioImageAnalysisFailedException $exception) {
        return $exception->error;
    }

    throw new RuntimeException('The photo was read.');
}

it('reads the feed variant when the photo has one', function () {
    $plan = studioPlanWithStoredPhoto();
    $variant = MediaVariant::query()->where('media_asset_id', $plan->image_asset_id)->sole();

    $image = app(StudioAnalysisImageReader::class)->read($plan->image_asset_id);

    expect($image->bytes)->toBe(Storage::disk('public')->get($variant->path))
        ->and($image->mimeType)->toBe('image/jpeg')
        ->and([$image->width, $image->height])->toBe([36, 24]);
});

it('reads the master when there is no feed variant, or it cannot be read', function (bool $variantRow) {
    $plan = studioPlanWithStoredPhoto(withFeedVariant: false);
    $asset = MediaAsset::query()->findOrFail($plan->image_asset_id);

    if ($variantRow) {
        MediaVariant::factory()->named(MediaVariantName::PostFeed1280)->create(['media_asset_id' => $asset->id]);
    }

    $image = app(StudioAnalysisImageReader::class)->read($asset->id);

    expect($image->bytes)->toBe(Storage::disk('public')->get($asset->path))
        ->and([$image->width, $image->height])->toBe([$asset->width, $asset->height]);
})->with(['no variant' => false, 'a variant whose file is missing' => true]);

it('never reads another variant in place of the feed one', function () {
    $plan = studioPlanWithStoredPhoto(withFeedVariant: false);
    $asset = MediaAsset::query()->findOrFail($plan->image_asset_id);
    $detail = MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create(['media_asset_id' => $asset->id]);
    Storage::disk('public')->put($detail->path, jpegMarkerBytes(64, 32));

    expect(app(StudioAnalysisImageReader::class)->read($asset->id)->bytes)->toBe(Storage::disk('public')->get($asset->path));
});

it('says the photo is missing when there is no such asset, or it was released', function () {
    $plan = studioPlanWithStoredPhoto();
    MediaAsset::query()->findOrFail($plan->image_asset_id)->delete();

    expect(readFailure($plan->image_asset_id))->toBe(StudioImageAnalysisError::ImageMissing)
        ->and(readFailure(999_999))->toBe(StudioImageAnalysisError::ImageMissing);
});

it('says the photo is unavailable when its file is gone', function () {
    $plan = StudioPlan::factory()->withImage()->create();

    expect(readFailure($plan->image_asset_id))->toBe(StudioImageAnalysisError::ImageUnavailable);
});

it('says the photo is unavailable when its bytes are not an image OpenAI takes', function (string $bytes) {
    $plan = studioPlanWithStoredPhoto(withFeedVariant: false);
    $asset = MediaAsset::query()->findOrFail($plan->image_asset_id);
    Storage::disk('public')->put($asset->path, $bytes);

    expect(readFailure($asset->id))->toBe(StudioImageAnalysisError::ImageUnavailable);
})->with([
    'text' => 'not an image at all',
    'empty' => '',
    'gif' => fn (): string => base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='),
]);

it('takes a PNG or a WebP by its own signature', function (string $format, string $type) {
    $plan = studioPlanWithStoredPhoto(withFeedVariant: false);
    $asset = MediaAsset::query()->findOrFail($plan->image_asset_id);
    Storage::disk('public')->put($asset->path, markerBytesWithAlpha($format));

    expect(app(StudioAnalysisImageReader::class)->read($asset->id)->mimeType)->toBe($type);
})->with([['png', 'image/png'], ['webp', 'image/webp']]);

it('reads no more than the limit, and says a larger photo is unavailable', function () {
    $plan = studioPlanWithStoredPhoto(withFeedVariant: false);
    $asset = MediaAsset::query()->findOrFail($plan->image_asset_id);
    $size = strlen(Storage::disk('public')->get($asset->path));

    config(['content_studio.image_analysis.max_image_bytes' => $size]);
    expect(strlen(app(StudioAnalysisImageReader::class)->read($asset->id)->bytes))->toBe($size);

    config(['content_studio.image_analysis.max_image_bytes' => $size - 1]);
    expect(readFailure($asset->id))->toBe(StudioImageAnalysisError::ImageUnavailable);
});

it('refuses a stored path that is a URL rather than reading it', function () {
    $plan = studioPlanWithStoredPhoto(withFeedVariant: false);
    MediaAsset::query()->whereKey($plan->image_asset_id)->update(['path' => 'https://example.com/photo.jpg']);

    expect(readFailure($plan->image_asset_id))->toBe(StudioImageAnalysisError::ImageUnavailable);
});

it('writes nothing while it reads', function () {
    $plan = studioPlanWithStoredPhoto();
    $before = Storage::disk('public')->allFiles();

    app(StudioAnalysisImageReader::class)->read($plan->image_asset_id);

    expect(Storage::disk('public')->allFiles())->toBe($before);
});

it('keeps the bytes out of a dump of what it read', function () {
    $plan = studioPlanWithStoredPhoto();
    $image = app(StudioAnalysisImageReader::class)->read($plan->image_asset_id);

    expect(print_r($image, true))->not->toContain(base64_encode($image->bytes))
        ->and(var_export($image->__debugInfo(), true))->toContain('byteSize');
});
