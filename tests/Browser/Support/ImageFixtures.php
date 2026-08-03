<?php

namespace Tests\Browser\Support;

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
 * machines that ran it during project setup. No Storage::fake (also
 * invisible to that fast path) and no upload pipeline involved. Call
 * cleanup() after each test that uses write().
 *
 * Usage in browser tests:
 *   'image_url' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16),
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

    /**
     * Writes a synthetic fixture PNG under the public/ directory and returns
     * the absolute image_url value to assign on a Post.
     */
    public static function write(int $width, int $height): string
    {
        $directory = self::directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $image = imagecreatetruecolor($width, $height);

        $background = imagecolorallocate($image, 88, 61, 158);
        imagefill($image, 0, 0, $background);

        $accent = imagecolorallocate($image, 247, 197, 92);
        imagefilledrectangle($image, 0, 0, (int) ($width / 5), (int) ($height / 5), $accent);

        $filename = 'fixture-'.$width.'x'.$height.'-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $directory.'/'.$filename);
        imagedestroy($image);

        return '/test-fixtures/'.self::worker().'/'.$filename;
    }

    /**
     * Removes every fixture written by write() during the test run.
     */
    public static function cleanup(): void
    {
        $directory = self::directory();

        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*.png') ?: [] as $file) {
            unlink($file);
        }
    }
}
