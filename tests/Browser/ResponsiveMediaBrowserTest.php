<?php

use App\Models\Post;
use Tests\Browser\Support\ImageFixtures;

afterEach(function () {
    ImageFixtures::cleanup();
});

it('renders a positive intrinsic width/height and a real srcset once variants exist', function () {
    Post::factory()->published()->create([
        'title' => 'Responsive Feed Post',
        'image_asset_id' => ImageFixtures::writeWithVariants(...ImageFixtures::LANDSCAPE_16X9)->id,
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 1000)
        ->assertSee('Responsive Feed Post');

    $selector = '[data-testid="post-card-image-open"] img';
    waitForImageLoaded($page, $selector);

    $attributes = $page->script(<<<JS
        (() => {
            const img = document.querySelector('{$selector}');
            return {
                width: img.getAttribute('width'),
                height: img.getAttribute('height'),
                srcset: img.getAttribute('srcset'),
                sizes: img.getAttribute('sizes'),
            };
        })()
    JS);

    expect((int) $attributes['width'])->toBeGreaterThan(0)
        ->and((int) $attributes['height'])->toBeGreaterThan(0)
        ->and($attributes['srcset'])->not->toBeEmpty()
        ->and($attributes['srcset'])->toContain('w,')
        ->and($attributes['sizes'])->not->toBeEmpty();

    $geometry = imageFitGeometry($page, $selector);
    expect($geometry['ratioDiff'])->toBeLessThan(0.05);
});

it('draws a photo smaller than its place at its own size, never stretched past its pixels, in the feed and on its page', function (int $width) {
    $post = Post::factory()->published()->create([
        'title' => 'Small Photo Post',
        'image_asset_id' => ImageFixtures::writeWithVariants(443, 460)->id,
    ]);
    $drawn = fn (mixed $page, string $selector): array => $page->script(<<<JS
        (() => {
            const img = document.querySelector('{$selector}');
            const shown = img.getBoundingClientRect();
            return { width: Math.round(shown.width), height: Math.round(shown.height), sizes: img.getAttribute('sizes') };
        })()
    JS);

    $page = visit(route('feed'))->resize($width, 1000)->assertSee('Small Photo Post');
    $selector = '[data-testid="post-card-image-open"] img';
    waitForImageLoaded($page, $selector);
    $feed = $drawn($page, $selector);

    $page = visit(route('posts.show', $post))->resize($width, 1000);
    $selector = '[data-testid="post-show-hero"] img';
    waitForImageLoaded($page, $selector);
    $show = $drawn($page, $selector);

    foreach ([$feed, $show] as $image) {
        expect($image['width'])->toBeLessThanOrEqual(443)->toBeGreaterThan(0)
            ->and($image['height'])->toBeLessThanOrEqual(460)
            ->and($image['sizes'])->toContain('443px');
    }

    // Wide enough for its own size, it has it.
    if ($width === 1440) {
        expect($feed['width'])->toBe(443)->and($show['width'])->toBe(443);
    }
})->with([1440, 390]);
