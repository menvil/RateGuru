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

// waitForImageLoaded()/imageFitGeometry() live in tests/Pest.php — shared
// with ResponsiveMediaBrowserTest, which requires them to be in Pest's
// always-loaded bootstrap rather than a bare function in this file (Pest's
// --parallel runner assigns whole files to separate worker processes, so a
// bare function here wouldn't be visible to that other file's worker).

it('does not crop a portrait image in the desktop feed and keeps it narrower than the card', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Portrait Feed Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::LANDSCAPE_16X9)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PANORAMA)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PANORAMA)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_3X4)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PANORAMA)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::LANDSCAPE_3X2)->id,
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
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_3X4)->id,
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
