<?php

use App\Models\Post;
use Illuminate\Support\Facades\Blade;

it('renders share buttons with theme token classes', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('rg-');
});

it('share buttons social row uses a share-sheet grid layout', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('grid-cols-4');
    expect($view)->toContain('sm:grid-cols-5');
});

it('share buttons url input is always visible', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    // The input should NOT have a static sr-only class applied
    expect($view)
        ->toContain('data-testid="copy-link-fallback-input"')
        ->not->toMatch('/data-testid="copy-link-fallback-input"[^>]*sr-only/');
});

it('provider links render brand-colored chips with visible captions', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('background-color: #1877F2');
    expect($view)->toContain('rounded-full');
    expect($view)->toContain('text-rg-text2');
});

it('copy link button has focus visible ring for accessibility', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)->toContain('focus-visible:ring-rg-accent');
});
