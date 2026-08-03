<?php

use App\Models\Post;
use Illuminate\Support\Facades\Blade;

it('renders nothing when there is no image url', function () {
    $post = Post::factory()->published()->make(['image_url' => null, 'image_path' => null]);

    $html = Blade::render('<x-media.post-image :post="$post" context="feed" />', ['post' => $post]);

    expect(trim($html))->toBe('');
});

it('renders the feed context without cropping classes', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render(
        '<x-media.post-image :post="$post" context="feed" open-fullscreen="imageOpen = true" testid="feed-image-open" />',
        ['post' => $post],
    );

    expect($html)
        ->toContain('/storage/posts/1/dish.jpg')
        ->toContain('alt="Dish"')
        ->toContain('object-contain')
        ->toContain('max-h-[75vh]')
        ->toContain('data-testid="feed-image-open"')
        ->toContain('decoding="async"')
        ->not->toContain('aspect-[16/10]')
        ->not->toContain('aspect-[4/3]')
        ->not->toContain('object-cover');
});

it('renders the drawer context without cropping classes', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render(
        '<x-media.post-image :post="$post" context="drawer" open-fullscreen="imageOpen = true" testid="drawer-image-open" />',
        ['post' => $post],
    );

    expect($html)
        ->toContain('object-contain')
        ->toContain('max-h-[70vh]')
        ->not->toContain('aspect-[4/3]')
        ->not->toContain('aspect-[16/10]')
        ->not->toContain('object-cover');
});

it('renders the standalone context without a fixed aspect ratio or crop', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render(
        '<x-media.post-image :post="$post" context="standalone" open-fullscreen="imageOpen = true" testid="standalone-image-open" />',
        ['post' => $post],
    );

    expect($html)
        ->toContain('object-contain')
        ->not->toContain('aspect-[16/10]')
        ->not->toContain('aspect-[4/3]')
        ->not->toContain('object-cover');
});

it('renders the fullscreen context with contain behavior and no click wrapper', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render(
        '<x-media.post-image :post="$post" context="fullscreen" image-testid="fullscreen-image" />',
        ['post' => $post],
    );

    expect($html)
        ->toContain('object-contain')
        ->toContain('max-h-[80vh]')
        ->toContain('data-testid="fullscreen-image"')
        ->not->toContain('<button')
        ->not->toContain('object-cover');
});

it('falls back to a neutral alt text when the post has no title', function () {
    $html = Blade::render(
        '<x-media.post-image src="https://example.test/dish.jpg" context="feed" />',
        [],
    );

    expect($html)->toContain('alt="Post image"');
});

it('applies the loading attribute only when explicitly provided', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $lazy = Blade::render(
        '<x-media.post-image :post="$post" context="feed" loading="lazy" />',
        ['post' => $post],
    );
    $eager = Blade::render(
        '<x-media.post-image :post="$post" context="feed" :loading="null" />',
        ['post' => $post],
    );

    expect($lazy)->toContain('loading="lazy"');
    expect($eager)->not->toContain('loading=');
});
