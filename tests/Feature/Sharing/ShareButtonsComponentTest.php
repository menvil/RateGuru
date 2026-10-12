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

it('shows pinterest with the fallback social image when post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_asset_id' => null,
    ]);

    $view = Blade::render('<x-sharing.share-buttons :post="$post" />', [
        'post' => $post,
    ]);

    expect($view)
        ->toContain('data-testid="share-pinterest"')
        ->toContain('rateguru-post-placeholder.png');
});

it('shows pinterest when post has image', function () {
    $post = Post::factory()->published()->withImage(path: 'posts/img.jpg')->create();

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

it('renders a provider share link as a plain anchor to its escaped share url', function (string $provider, string $url) {
    $html = Blade::render(
        '<x-share.provider-link :provider="$provider" :url="$url" label="Share" />',
        ['provider' => $provider, 'url' => $url],
    );

    expect($html)
        ->toContain('data-testid="share-'.$provider.'"')
        ->toContain('href="'.e($url).'"')
        ->not->toContain('window.open');
})->with([
    'facebook' => ['facebook', 'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Frateguru.test%2Fposts%2F1'],
    'x' => ['x', 'https://twitter.com/intent/tweet?url=https%3A%2F%2Frateguru.test%2Fposts%2F1&text=A+post'],
    'telegram' => ['telegram', 'https://t.me/share/url?url=https%3A%2F%2Frateguru.test%2Fposts%2F1&text=A+post'],
    'whatsapp' => ['whatsapp', 'https://wa.me/?text=A+post+https%3A%2F%2Frateguru.test%2Fposts%2F1'],
    'reddit' => ['reddit', 'https://www.reddit.com/submit?url=https%3A%2F%2Frateguru.test%2Fposts%2F1&title=A+post'],
    'pinterest' => ['pinterest', 'https://pinterest.com/pin/create/button/?url=https%3A%2F%2Frateguru.test%2Fposts%2F1&media=https%3A%2F%2Frateguru.test%2Fimg.jpg&description=A+post'],
    'email' => ['email', 'mailto:?subject=A%20post&body=https%3A%2F%2Frateguru.test%2Fposts%2F1'],
]);
