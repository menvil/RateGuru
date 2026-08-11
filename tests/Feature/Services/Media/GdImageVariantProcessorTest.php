<?php

use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Services\Media\Exceptions\MediaVariantGenerationException;
use App\Services\Media\GdImageVariantProcessor;

// containResizeMarkerBytes()/coverSquareCropMarkerBytes()/
// solidCornerBlockJpeg()/variantSpec() live in tests/Pest.php — shared with
// MediaVariantWriterTest, which requires them to be in Pest's always-loaded
// bootstrap rather than a bare function in this file (see the comment
// there).

it('contain-resizes without cropping, matching every hand-verified example', function (int $srcW, int $srcH, int $maxW, int $maxH, int $expectedW, int $expectedH) {
    $bytes = containResizeMarkerBytes($srcW, $srcH);
    $spec = variantSpec(maxWidth: $maxW, maxHeight: $maxH);

    $variant = (new GdImageVariantProcessor)->generate($bytes, 'image/jpeg', $spec);

    expect($variant->width)->toBe($expectedW)
        ->and($variant->height)->toBe($expectedH);

    $corners = markerCorners($variant->bytes);
    expect($corners['tl'])->toBe('RED')
        ->and($corners['tr'])->toBe('GREEN')
        ->and($corners['bl'])->toBe('BLUE')
        ->and($corners['br'])->toBe('WHITE');
})->with([
    'landscape 2400x1600 -> 640x427' => [2400, 1600, 640, 1280, 640, 427],
    'portrait 1200x2400 -> 640x1280' => [1200, 2400, 640, 1280, 640, 1280],
    'square 1000x1000 -> 640x640' => [1000, 1000, 640, 1280, 640, 640],
    'small 320x200 -> unchanged, no upscale' => [320, 200, 640, 1280, 320, 200],
    'ultra-wide 4000x500 -> 640x80' => [4000, 500, 640, 1280, 640, 80],
]);

it('never upscales a contain variant even when both bounds exceed the source', function () {
    $bytes = jpegMarkerBytes(100, 50);
    $spec = variantSpec(maxWidth: 640, maxHeight: 1280);

    $variant = (new GdImageVariantProcessor)->generate($bytes, 'image/jpeg', $spec);

    expect($variant->width)->toBe(100)
        ->and($variant->height)->toBe(50);
});

it('crops the largest centered square before resizing for cover-square mode', function (int $srcW, int $srcH) {
    $bytes = coverSquareCropMarkerBytes($srcW, $srcH);
    $spec = variantSpec(MediaVariantName::Avatar128, maxWidth: 50, maxHeight: 50, mode: MediaResizeMode::CoverSquare);

    $variant = (new GdImageVariantProcessor)->generate($bytes, 'image/jpeg', $spec);

    expect($variant->width)->toBe(50)
        ->and($variant->height)->toBe(50);

    $corners = markerCorners($variant->bytes);
    expect($corners['tl'])->toBe('RED')
        ->and($corners['tr'])->toBe('GREEN')
        ->and($corners['bl'])->toBe('BLUE')
        ->and($corners['br'])->toBe('WHITE');
})->with([
    'landscape source' => [200, 100],
    'portrait source' => [100, 200],
    'square source' => [150, 150],
]);

it('preserves alpha through a contain resize for png', function () {
    $bytes = markerBytesWithAlpha('png', 200, 100);
    $spec = variantSpec(maxWidth: 100, maxHeight: 100);

    $variant = (new GdImageVariantProcessor)->generate($bytes, 'image/png', $spec);

    $image = imagecreatefromstring($variant->bytes);
    // The bottom-right pixel was never painted a marker color in
    // markerBytesWithAlpha() — it's still the fully transparent fill.
    $rgba = imagecolorsforindex($image, imagecolorat($image, imagesx($image) - 1, imagesy($image) - 1));

    expect($rgba['alpha'])->toBe(127);
});

it('preserves alpha through a contain resize for webp', function () {
    $bytes = markerBytesWithAlpha('webp', 200, 100);
    $spec = variantSpec(maxWidth: 100, maxHeight: 100);

    $variant = (new GdImageVariantProcessor)->generate($bytes, 'image/webp', $spec);

    $image = imagecreatefromstring($variant->bytes);
    $rgba = imagecolorsforindex($image, imagecolorat($image, imagesx($image) - 1, imagesy($image) - 1));

    expect($rgba['alpha'])->toBe(127);
});

it('produces the mime type and extension matching the source format', function (string $mime, string $extension, Closure $bytesFactory) {
    $bytes = $bytesFactory();
    $spec = variantSpec(maxWidth: 50, maxHeight: 50);

    $variant = (new GdImageVariantProcessor)->generate($bytes, $mime, $spec);

    expect($variant->mimeType)->toBe($mime)
        ->and($variant->extension)->toBe($extension)
        ->and($variant->byteSize)->toBe(strlen($variant->bytes));
})->with([
    'jpeg' => ['image/jpeg', 'jpg', fn () => jpegMarkerBytes(100, 100)],
    'png' => ['image/png', 'png', fn () => markerBytesWithAlpha('png', 100, 100)],
    'webp' => ['image/webp', 'webp', fn () => markerBytesWithAlpha('webp', 100, 100)],
]);

it('throws a MediaVariantGenerationException for undecodable bytes', function () {
    $spec = variantSpec();

    expect(fn () => (new GdImageVariantProcessor)->generate('not an image', 'image/jpeg', $spec))
        ->toThrow(MediaVariantGenerationException::class);
});
