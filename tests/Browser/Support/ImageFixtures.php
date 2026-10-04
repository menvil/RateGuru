<?php

namespace Tests\Browser\Support;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Services\Media\MediaVariantGenerator;
use App\Support\Media\ImageOrientationClassifier;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * Synthetic post-image fixtures for media rendering browser tests.
 *
 * Dimensions are deliberately small (fast to generate/encode) but keep the
 * exact aspect ratio implied by their name, since these tests assert on
 * rendered aspect ratio, not resolution.
 *
 * Pest's browser driver serves the app in-process, and for any request path
 * that isn't handled by Laravel routing it first checks
 * file_exists(public_path($path)) and serves it directly from disk (see
 * Pest\Browser\Drivers\LaravelHttpServer::handleRequest). Fixtures are
 * written straight under public/test-fixtures/ so they're reachable that way
 * with no dependency on the storage:link symlink existing — which it does
 * not in CI (the workflow never runs `artisan storage:link`), only on dev
 * machines that ran it during project setup. A dedicated filesystem disk is
 * registered at runtime pointing at that same directory, so the written file
 * has a real disk + path identity a MediaAsset can reference — no
 * Storage::fake (also invisible to that fast path) and no upload pipeline
 * involved. Call cleanup() after each test that uses write().
 *
 * Usage in browser tests:
 *   'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
 */
final class ImageFixtures
{
    /** @var array{0:int,1:int} */
    public const PORTRAIT_9X16 = [360, 640];

    /** @var array{0:int,1:int} */
    public const PORTRAIT_3X4 = [450, 600];

    // Landscape/panorama/square fixtures are sized larger than a typical feed
    // card width on purpose: they need to exceed the container so max-width:
    // 100% actually scales them down, exercising the "landscape uses the
    // available width" behavior instead of just rendering at natural size.
    /** @var array{0:int,1:int} */
    public const LANDSCAPE_16X9 = [1280, 720];

    /** @var array{0:int,1:int} */
    public const LANDSCAPE_3X2 = [1200, 800];

    /** @var array{0:int,1:int} */
    public const SQUARE = [1000, 1000];

    /** @var array{0:int,1:int} */
    public const PANORAMA = [1920, 640];

    /**
     * Namespaced per parallel-testing worker (CI runs `php artisan test
     * --parallel`) so cleanup() never deletes fixtures a sibling worker is
     * still using — each worker only ever touches its own subdirectory.
     */
    private static function worker(): string
    {
        return (string) (ParallelTesting::token() ?: 'main');
    }

    private static function directory(): string
    {
        return public_path('test-fixtures/'.self::worker());
    }

    private static function diskName(): string
    {
        return 'test_fixtures_'.self::worker();
    }

    /**
     * Writes a synthetic fixture PNG under public/ and registers a
     * throwaway filesystem disk rooted at that same directory, then creates
     * and returns a MediaAsset (kind: post_image) with disk + path pointing
     * at it.
     */
    public static function write(int $width, int $height): MediaAsset
    {
        $directory = self::directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $diskName = self::diskName();
        config()->set("filesystems.disks.{$diskName}", [
            'driver' => 'local',
            'root' => $directory,
            'url' => '/test-fixtures/'.self::worker(),
            'visibility' => 'public',
        ]);

        $image = imagecreatetruecolor($width, $height);

        $background = imagecolorallocate($image, 88, 61, 158);
        imagefill($image, 0, 0, $background);

        $accent = imagecolorallocate($image, 247, 197, 92);
        imagefilledrectangle($image, 0, 0, (int) ($width / 5), (int) ($height / 5), $accent);

        $filename = 'fixture-'.$width.'x'.$height.'-'.bin2hex(random_bytes(4)).'.png';
        $path = $directory.'/'.$filename;
        imagepng($image, $path);
        imagedestroy($image);

        return MediaAsset::create([
            'kind' => MediaKind::PostImage,
            'disk' => $diskName,
            'path' => $filename,
            'original_filename' => $filename,
            'mime_type' => 'image/png',
            'extension' => 'png',
            'byte_size' => filesize($path) ?: 0,
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => round($width / $height, 6),
            'orientation' => app(ImageOrientationClassifier::class)->classify($width, $height),
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
    }

    /**
     * Same as write(), but also synchronously generates every applicable
     * responsive variant for the fixture, so browser tests can assert on
     * srcset/fetchpriority behavior instead of always exercising the
     * master-fallback path.
     */
    public static function writeWithVariants(int $width, int $height): MediaAsset
    {
        $asset = self::write($width, $height);

        app(MediaVariantGenerator::class)->generateAll($asset);

        return $asset->fresh();
    }

    /**
     * Removes every fixture written by write()/writeWithVariants() during
     * the test run, including nested variant subdirectories (variants are
     * always nested one level under the master's own filename — see
     * MediaVariantPathGenerator — so a flat glob would miss them).
     */
    public static function cleanup(): void
    {
        $directory = self::directory();

        if (! is_dir($directory)) {
            return;
        }

        self::removeDirectoryContents($directory);
    }

    private static function removeDirectoryContents(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                self::removeDirectoryContents($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }
}
