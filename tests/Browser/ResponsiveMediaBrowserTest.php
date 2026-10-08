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
