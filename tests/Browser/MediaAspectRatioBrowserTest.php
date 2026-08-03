<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use Tests\Browser\Support\ImageFixtures;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\actingAs;

afterEach(function () {
    ImageFixtures::cleanup();
});

/**
 * Several contexts (drawer, fullscreen, non-first feed cards) render
 * loading="lazy". Reading naturalWidth/naturalHeight before the browser has
 * actually decoded the image would yield 0/0 → a NaN ratio that fails the
 * assertion regardless of fit, nondeterministically depending on load
 * timing (worse under CI's --parallel, where workers compete for CPU).
 * Poll until the image reports as loaded before measuring it.
 */
function waitForImageLoaded(mixed $page, string $selector, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $loaded = $page->script(<<<JS
            (() => {
                const img = document.querySelector('{$selector}');
                return !!img && img.complete && img.naturalWidth > 0;
            })()
        JS);

        if ($loaded) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException("Image [{$selector}] did not finish loading within {$timeoutSeconds}s.");
}

/**
 * Compares the rendered <img> box ratio against the image's own natural
 * ratio. object-fit: cover would force the box toward the *container's*
 * ratio instead, so a mismatch here is exactly what would catch a
 * regression back to cropping.
 *
 * @return array{naturalWidth: int, naturalHeight: int, width: float, height: float, ratioDiff: float}
 */
function imageFitGeometry(mixed $page, string $selector): array
{
    waitForImageLoaded($page, $selector);

    $geometry = $page->script(<<<JS
        (() => {
            const img = document.querySelector('{$selector}');
            const rect = img.getBoundingClientRect();
            return {
                naturalWidth: img.naturalWidth,
                naturalHeight: img.naturalHeight,
                width: rect.width,
                height: rect.height,
            };
        })()
    JS);

    $naturalRatio = $geometry['naturalWidth'] / $geometry['naturalHeight'];
    $renderedRatio = $geometry['width'] / $geometry['height'];

    $geometry['ratioDiff'] = abs($naturalRatio - $renderedRatio);

    return $geometry;
}

it('does not crop a portrait image in the desktop feed and keeps it narrower than the card', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Portrait Feed Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16),
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('Portrait Feed Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');
    $cardWidth = $page->script('document.querySelector(\'[data-testid="post-card"]\').getBoundingClientRect().width');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($geometry['width'])->toBeLessThan($cardWidth * 0.9);
});

it('lets a landscape image use the available card width in the desktop feed', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Landscape Feed Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::LANDSCAPE_16X9),
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('Landscape Feed Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');
    $cardWidth = $page->script('document.querySelector(\'[data-testid="post-card"]\').getBoundingClientRect().width');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($geometry['width'])->toBeGreaterThan($cardWidth * 0.9);
});

it('does not crop a panorama image in the desktop feed', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Panorama Feed Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PANORAMA),
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('Panorama Feed Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05);
});

it('does not horizontally overflow the mobile feed with a portrait image', function () {
    Post::factory()->published()->create([
        'title' => 'Mobile Portrait Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16),
    ]);

    $page = visit(route('feed'))
        ->resize(...MobileViewports::MOBILE)
        ->wait(0.4)
        ->assertSee('Mobile Portrait Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');
    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($overflow)->toBeLessThanOrEqual(1);
});

it('does not horizontally overflow the tablet feed with a panorama image', function () {
    Post::factory()->published()->create([
        'title' => 'Tablet Panorama Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PANORAMA),
    ]);

    $page = visit(route('feed'))
        ->resize(...MobileViewports::TABLET)
        ->wait(0.4)
        ->assertSee('Tablet Panorama Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');
    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($overflow)->toBeLessThanOrEqual(1);
});

it('shows the full image in the desktop split-view drawer without cropping or page overflow', function () {
    Post::factory()->published()->create([
        'title' => 'Drawer Portrait Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PORTRAIT_3X4),
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 1000)
        ->wait(0.2)
        // Click the title rather than the card root: a tall portrait image can
        // dominate the card's bounding box, and clicking the card's center
        // would land on the image's own click-to-zoom button instead of
        // bubbling up to the card's select-post handler.
        ->click('[data-testid="post-card-title"]')
        ->waitForText('Drawer Portrait Post')
        ->assertVisible('[data-testid="post-detail-column"] [data-testid="post-drawer"]')
        ->wait(0.4);

    $geometry = imageFitGeometry($page, '[data-testid="post-detail-column"] [data-testid="post-drawer-image-open"] img');
    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($overflow)->toBeLessThanOrEqual(1);
});

it('does not crop the standalone post image and does not overflow the viewport', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Standalone Portrait Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16),
    ]);

    $page = visit(route('posts.show', $post))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('Standalone Portrait Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-show-image-open"] img');
    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($overflow)->toBeLessThanOrEqual(1);
});

it('preserves contain behavior for the fullscreen image and does not stretch it', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Fullscreen Panorama Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PANORAMA),
    ]);

    $page = visit(route('posts.show', $post))
        ->resize(1440, 1000)
        ->wait(0.2)
        ->click('[data-testid="post-show-image-open"]')
        ->wait(0.4);

    $geometry = imageFitGeometry($page, '[data-testid="post-fullscreen-image"]');
    $viewportHeight = $page->script('window.innerHeight');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05)
        ->and($geometry['height'])->toBeLessThanOrEqual($viewportHeight * 0.82);
});

it('does not crop post images in the profile feed', function () {
    $author = User::factory()->create(['username' => 'media_profile_author']);
    Post::factory()->published()->for($author)->create([
        'title' => 'Profile Feed Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::LANDSCAPE_3X2),
    ]);

    $page = visit(route('profile.show', 'media_profile_author'))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('Profile Feed Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05);
});

it('does not crop post images on the saved posts page', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Saved Portrait Post',
        'image_path' => ImageFixtures::write(...ImageFixtures::PORTRAIT_3X4),
    ]);
    PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]);

    actingAs($user);

    $page = visit(route('saved-posts.index'))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('Saved Portrait Post');

    $geometry = imageFitGeometry($page, '[data-testid="post-card-image-open"] img');

    expect($geometry['ratioDiff'])->toBeLessThan(0.05);
});
