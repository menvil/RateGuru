<?php

use App\Models\Post;
use Illuminate\Support\Facades\Blade;

beforeEach(function () {
    config(['app.url' => 'https://rateguru.test']);
});

it('renders reusable share buttons component', function () {
    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('data-testid="share-buttons"');
    expect($view)->toContain('data-testid="share-copy-link"');
});

it('renders social provider links', function () {
    $post = Post::factory()->published()->create(['title' => 'Provider Test Post']);

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('data-testid="share-facebook"');
    expect($view)->toContain('data-testid="share-x"');
    expect($view)->toContain('data-testid="share-telegram"');
    expect($view)->toContain('data-testid="share-whatsapp"');
    expect($view)->toContain('data-testid="share-reddit"');
    expect($view)->toContain('data-testid="share-email"');
});

it('hides pinterest when post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_path' => null,
        'image_url' => null,
    ]);

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->not->toContain('data-testid="share-pinterest"');
});

it('shows pinterest when post has image', function () {
    $post = Post::factory()->published()->create([
        'image_url' => 'https://rateguru.test/storage/posts/img.jpg',
    ]);

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('data-testid="share-pinterest"');
});

it('renders native share button in share buttons component', function () {
    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->toContain('data-testid="share-native"');
    expect($view)->toContain('rgNativeShare');
});

it('respects disabled providers in config', function () {
    config(['share.providers.facebook.enabled' => false]);

    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)->not->toContain('data-testid="share-facebook"');
    expect($view)->toContain('data-testid="share-x"');
});
