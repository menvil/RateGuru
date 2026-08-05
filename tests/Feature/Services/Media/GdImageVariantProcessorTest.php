<?php

use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Services\Media\Exceptions\MediaVariantGenerationException;
use App\Services\Media\GdImageVariantProcessor;
use App\Services\Media\MediaVariantSpecification;

/**
 * Solid corner BLOCKS (not single pixels, unlike GdImageIngestorTest's own
 * makeMarkerImage()) — a single marker pixel gets diluted below
 * markerCornerColor()'s detection threshold once imagecopyresampled()'s
 * interpolation and JPEG's lossy compression both apply, which never
 * happens to GdImageIngestorTest's own pixel-preserving flip/rotate. Block
 * size scales with the image so it survives even the most aggressive
 * downscale in this file's own dataset (4000x500 -> 640x80, ~0.16x).
 */
function containResizeMarkerBytes(int $width, int $height): string
{
    return solidCornerBlockJpeg($width, $height, 0, 0, $width, $height);
}

/**
 * Places the four marker color blocks at the region that will become a
 * CoverSquare crop's four corners, rather than the source image's own
 * absolute corners — lets a crop+resize be verified with the same
 * markerCorners() reader GdImageIngestorTest already defines.
 */
function coverSquareCropMarkerBytes(int $width, int $height): string
{
    $cropSize = min($width, $height);
    $cropX = intdiv($width - $cropSize, 2);
    $cropY = intdiv($height - $cropSize, 2);

    return solidCornerBlockJpeg($width, $height, $cropX, $cropY, $cropSize, $cropSize);
}

function solidCornerBlockJpeg(int $width, int $height, int $regionX, int $regionY, int $regionW, int $regionH): string
{
    $blockW = max(4, (int) round($regionW * 0.15));
    $blockH = max(4, (int) round($regionH * 0.15));

    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));

    $red = imagecolorallocate($im, 255, 0, 0);
    $green = imagecolorallocate($im, 0, 255, 0);
    $blue = imagecolorallocate($im, 0, 0, 255);
    $white = imagecolorallocate($im, 255, 255, 255);

    imagefilledrectangle($im, $regionX, $regionY, $regionX + $blockW - 1, $regionY + $blockH - 1, $red);
    imagefilledrectangle($im, $regionX + $regionW - $blockW, $regionY, $regionX + $regionW - 1, $regionY + $blockH - 1, $green);
    imagefilledrectangle($im, $regionX, $regionY + $regionH - $blockH, $regionX + $blockW - 1, $regionY + $regionH - 1, $blue);
    imagefilledrectangle($im, $regionX + $regionW - $blockW, $regionY + $regionH - $blockH, $regionX + $regionW - 1, $regionY + $regionH - 1, $white);

    ob_start();
    imagejpeg($im, null, 90);

    return ob_get_clean();
}

function variantSpec(
    MediaVariantName $name = MediaVariantName::PostFeed640,
    int $maxWidth = 640,
    int $maxHeight = 1280,
    MediaResizeMode $mode = MediaResizeMode::Contain,
    int $quality = 82,
): MediaVariantSpecification {
    return new MediaVariantSpecification($name, $maxWidth, $maxHeight, $mode, $quality);
}

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
