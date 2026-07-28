<?php

use App\Models\Post;

it('renders copy link button on post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Browser Share Test Post',
    ]);

    visit(route('posts.show', $post))
        ->assertPresent('[data-testid="share-copy-link"]');
});

it('renders platform share links on post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Browser Provider Links Test',
    ]);

    visit(route('posts.show', $post))
        ->assertPresent('[data-testid="share-facebook"]')
        ->assertPresent('[data-testid="share-x"]')
        ->assertPresent('[data-testid="share-telegram"]')
        ->assertPresent('[data-testid="share-whatsapp"]')
        ->assertPresent('[data-testid="share-reddit"]')
        ->assertPresent('[data-testid="share-email"]');
});

it('all platform share buttons expose provider urls in their href', function () {
    $post = Post::factory()->published()->create();

    $providers = [
        'share-facebook' => 'facebook.com',
        'share-x' => 'twitter.com/intent/tweet',
        'share-telegram' => 't.me/share/url',
        'share-whatsapp' => 'wa.me',
        'share-reddit' => 'reddit.com/submit',
        'share-email' => 'mailto:',
    ];

    $page = visit(route('posts.show', $post));

    foreach ($providers as $testid => $providerUrl) {
        $page->assertAttributeContains("[data-testid=\"{$testid}\"]", 'href', $providerUrl);
    }
});

it('renders share buttons container on post show page', function () {
    $post = Post::factory()->published()->create();

    visit(route('posts.show', $post))
        ->assertPresent('[data-testid="share-buttons"]');
});

it('renders native share button marker on post show page', function () {
    $post = Post::factory()->published()->create();

    visit(route('posts.show', $post))
        ->assertPresent('[data-testid="share-native"]');
});
