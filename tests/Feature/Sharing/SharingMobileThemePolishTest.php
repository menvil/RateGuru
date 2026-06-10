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

it('share buttons social row uses flex wrap layout', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('flex');
    expect($view)->toContain('flex-wrap');
});

it('share buttons url input is always visible', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    // The input should NOT be sr-only by default
    expect($view)->not->toContain("'sr-only': ! manualCopy");
    expect($view)->toContain('data-testid="copy-link-fallback-input"');
});

it('provider link uses border-rg-border and bg-rg-card2 theme tokens', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('border-rg-border');
    expect($view)->toContain('bg-rg-card2');
    expect($view)->toContain('text-rg-text2');
});

it('copy link button has focus visible ring for accessibility', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)->toContain('focus-visible:ring-rg-accent');
});
