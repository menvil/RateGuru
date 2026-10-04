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
        ->wait(0.4)
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

it('marks only the first feed image eager with fetchpriority=high, others lazy', function () {
    Post::factory()->published()->create([
        'title' => 'First Responsive Post',
        'image_asset_id' => ImageFixtures::writeWithVariants(...ImageFixtures::LANDSCAPE_16X9)->id,
        'published_at' => now(),
    ]);
    Post::factory()->published()->create([
        'title' => 'Second Responsive Post',
        'image_asset_id' => ImageFixtures::writeWithVariants(...ImageFixtures::PORTRAIT_3X4)->id,
        'published_at' => now()->subMinute(),
    ]);

    $page = visit(route('feed', ['sort' => 'newest']))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('First Responsive Post')
        ->assertSee('Second Responsive Post');

    $loadingAttributes = $page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-testid="post-card-image-open"] img')).map((img) => ({
            loading: img.getAttribute('loading'),
            fetchpriority: img.getAttribute('fetchpriority'),
        }))
    JS);

    expect($loadingAttributes)->toHaveCount(2);
    expect($loadingAttributes[0]['loading'])->toBeNull()
        ->and($loadingAttributes[0]['fetchpriority'])->toBe('high');
    expect($loadingAttributes[1]['loading'])->toBe('lazy')
        ->and($loadingAttributes[1]['fetchpriority'])->toBeNull();
});

it('still falls back to the master image with no srcset when no variants have been generated', function () {
    Post::factory()->published()->create([
        'title' => 'No Variants Yet Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::LANDSCAPE_16X9)->id,
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 1000)
        ->wait(0.4)
        ->assertSee('No Variants Yet Post');

    $selector = '[data-testid="post-card-image-open"] img';
    waitForImageLoaded($page, $selector);

    $srcset = $page->script("document.querySelector('{$selector}').getAttribute('srcset')");

    expect($srcset)->toBeNull();
});
