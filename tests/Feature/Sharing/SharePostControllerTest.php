<?php

use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;

it('sends a share button on to the provider\'s share page for the post', function (string $provider) {
    $post = Post::factory()->published()->create(['title' => 'A post worth sharing']);

    $this->get(route('posts.share', ['post' => $post, 'provider' => $provider]))
        ->assertRedirect(app(ShareUrlBuilder::class)->build($provider, app(PostShareMetadata::class)->forPost($post)));
})->with(['facebook', 'x', 'telegram', 'whatsapp', 'reddit', 'pinterest']);

it('pins the fallback social image for a post without one', function () {
    $post = Post::factory()->published()->create(['image_asset_id' => null]);

    expect($this->get(route('posts.share', ['post' => $post, 'provider' => 'pinterest']))->headers->get('Location'))
        ->toStartWith('https://pinterest.com/pin/create/button/')
        ->toContain(urlencode('rateguru-post-placeholder.png'));
});

it('shares nothing about a post its own page would not show', function (string $state) {
    $post = Post::factory()->{$state}()->create(['title' => 'Not for sharing']);

    $this->get(route('posts.share', ['post' => $post, 'provider' => 'x']))->assertNotFound();
})->with(['pending', 'rejected', 'hidden']);

it('answers a missing post with not found', function () {
    $this->get('/posts/999999/share/x')->assertNotFound();
});

it('redirects only for a social provider that is switched on', function (string $provider) {
    config(['share.providers.facebook.enabled' => false]);

    $post = Post::factory()->published()->create();

    $this->get("/posts/{$post->id}/share/{$provider}")->assertNotFound();
})->with([
    'switched off' => 'facebook',
    'email, a mailto: link with nothing to redirect to' => 'email',
    'copy link' => 'copy_link',
    'native share' => 'native',
    'unknown' => 'myspace',
]);
