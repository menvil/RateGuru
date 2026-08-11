<?php

use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\NormalizedImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The single committed source of truth for which infrastructure CLIs must
 * stay executable — also read (independently, at runtime) by both
 * deploy-staging.yml and release.yml, so the allowlist enforced by
 * InfrastructureScriptExecutableModesTest, DeployStagingWorkflowTest and
 * ProductionReleaseWorkflowTest can never drift from what the artifact-build
 * workflows actually verify.
 *
 * @return list<string>
 */
function requiredCliManifestNames(): array
{
    $manifestPath = base_path('infrastructure/config/required-clis.txt');
    $contents = file_get_contents($manifestPath);

    if ($contents === false) {
        throw new RuntimeException("could not read the required-CLI manifest: {$manifestPath}");
    }

    $lines = preg_split('/\R/', $contents);

    return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
}

/**
 * A correctly normalized release-tree fixture: every manifested CLI present
 * and executable, common present, readable and non-executable, the manifest
 * itself copied verbatim from the real committed one. Shared by
 * InfrastructureScriptExecutableModesTest (testing verify-required-clis and
 * deploy directly) and DeployStagingWorkflowTest/ProductionReleaseWorkflowTest
 * (testing that each workflow correctly delegates to it).
 */
function releaseCliFixture(array $cliNames): string
{
    $root = sys_get_temp_dir().'/release-cli-exec-check-'.uniqid('', true);

    mkdir($root.'/infrastructure/scripts', 0o755, true);
    mkdir($root.'/infrastructure/config', 0o755, true);
    copy(base_path('infrastructure/config/required-clis.txt'), $root.'/infrastructure/config/required-clis.txt');

    foreach ($cliNames as $name) {
        file_put_contents($root.'/infrastructure/scripts/'.$name, "#!/usr/bin/env bash\n");
        chmod($root.'/infrastructure/scripts/'.$name, 0o755);
    }

    file_put_contents($root.'/infrastructure/scripts/common', "#!/usr/bin/env bash\n");
    chmod($root.'/infrastructure/scripts/common', 0o644);

    return $root;
}

/**
 * A minimal NormalizedImage fixture shared by every Media test that needs
 * one to hand to MediaStorage::storeNormalized() without running a real
 * image through GdImageIngestor first.
 */
function normalizedFixture(string $bytes = 'normalized-jpeg-bytes'): NormalizedImage
{
    return new NormalizedImage(
        bytes: $bytes,
        mimeType: 'image/jpeg',
        extension: 'jpg',
        byteSize: strlen($bytes),
        width: 800,
        height: 600,
    );
}

/**
 * Create two configurable Rating Groups used by feed filter tests.
 */
function seedFeedFilterGroups(): void
{
    $type = RatingGroup::factory()->create(['key' => 'type', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_b', 'sort_order' => 20]);

    $attribute = RatingGroup::factory()->create(['key' => 'attribute', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_b', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_c', 'sort_order' => 30]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_d', 'sort_order' => 40]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_other', 'sort_order' => 50]);
}

/**
 * The following image-marker helpers are shared across multiple Media test
 * files (GdImageIngestorTest, GdImageVariantProcessorTest, and everything
 * that generates fixture bytes for MediaVariantGenerator/Writer/Job/Command
 * tests). They live here — not as bare functions inside any one of those
 * files — because Pest's parallel runner (`--parallel`, used in CI) assigns
 * whole test files to separate worker processes; a bare function declared in
 * file A is not visible to file B when they land in different workers, which
 * only surfaces as an "undefined function" failure under --parallel, not
 * under a normal sequential run. Everything in this file, by contrast, is
 * part of Pest's own bootstrap and is loaded by every worker.
 */

/**
 * Distinct marker colors in each corner: TL=red, TR=green, BL=blue,
 * BR=white — lets orientation tests assert on physical pixel positions, not
 * just reported dimensions.
 */
function makeMarkerImage(int $width, int $height): GdImage
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, 255, 0, 0));
    imagesetpixel($im, $width - 1, 0, imagecolorallocate($im, 0, 255, 0));
    imagesetpixel($im, 0, $height - 1, imagecolorallocate($im, 0, 0, 255));
    imagesetpixel($im, $width - 1, $height - 1, imagecolorallocate($im, 255, 255, 255));

    return $im;
}

function markerCornerColor(GdImage $im, int $x, int $y): string
{
    $rgb = imagecolorsforindex($im, imagecolorat($im, $x, $y));

    return match (true) {
        $rgb['red'] > 200 && $rgb['green'] < 50 && $rgb['blue'] < 50 => 'RED',
        $rgb['green'] > 200 && $rgb['red'] < 50 && $rgb['blue'] < 50 => 'GREEN',
        $rgb['blue'] > 200 && $rgb['red'] < 50 && $rgb['green'] < 50 => 'BLUE',
        $rgb['red'] > 200 && $rgb['green'] > 200 && $rgb['blue'] > 200 => 'WHITE',
        default => 'OTHER',
    };
}

/** @return array{tl: string, tr: string, bl: string, br: string, width: int, height: int} */
function markerCorners(string $bytes): array
{
    $im = imagecreatefromstring($bytes);
    $w = imagesx($im);
    $h = imagesy($im);

    return [
        'tl' => markerCornerColor($im, 0, 0),
        'tr' => markerCornerColor($im, $w - 1, 0),
        'bl' => markerCornerColor($im, 0, $h - 1),
        'br' => markerCornerColor($im, $w - 1, $h - 1),
        'width' => $w,
        'height' => $h,
    ];
}

function jpegMarkerBytes(int $width = 20, int $height = 10, int $quality = 90): string
{
    $im = makeMarkerImage($width, $height);
    ob_start();
    imagejpeg($im, null, $quality);

    return ob_get_clean();
}

/** @param 'png'|'webp' $format */
function markerBytesWithAlpha(string $format, int $width = 20, int $height = 10): string
{
    $im = imagecreatetruecolor($width, $height);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagesetpixel($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 0));
    imagesetpixel($im, $width - 1, 0, imagecolorallocatealpha($im, 0, 255, 0, 0));
    imagesetpixel($im, 0, $height - 1, imagecolorallocatealpha($im, 0, 0, 255, 0));
    ob_start();

    match ($format) {
        'png' => imagepng($im),
        'webp' => imagewebp($im),
    };

    return ob_get_clean();
}

/**
 * Solid corner BLOCKS (not single pixels, unlike makeMarkerImage() above) —
 * a single marker pixel gets diluted below markerCornerColor()'s detection
 * threshold once imagecopyresampled()'s interpolation and JPEG's lossy
 * compression both apply, which never happens to makeMarkerImage()'s own
 * pixel-preserving flip/rotate use in GdImageIngestorTest. Block size scales
 * with the image so it survives even an aggressive downscale (e.g.
 * 4000x500 -> 640x80, ~0.16x, exercised in GdImageVariantProcessorTest).
 */
function containResizeMarkerBytes(int $width, int $height): string
{
    return solidCornerBlockJpeg($width, $height, 0, 0, $width, $height);
}

/**
 * Places the four marker color blocks at the region that will become a
 * CoverSquare crop's four corners, rather than the source image's own
 * absolute corners — lets a crop+resize be verified with the same
 * markerCorners() reader above.
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
