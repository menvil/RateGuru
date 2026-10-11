<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;

it('hides share buttons on post show when feature flag disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => false,
        ],
    ]);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="share-buttons"', false);
});

it('shows share buttons on the published post page, each provider link pointing at that provider', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_share_buttons' => true]]);

    $post = Post::factory()->published()->create();
    $response = $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="share-buttons"', false)
        ->assertSee('data-testid="share-copy-link"', false)
        ->assertSee('data-testid="share-facebook"', false)
        ->assertSee('data-testid="share-x"', false);
    $page = livewireDom($response->getContent());
    $metadata = app(PostShareMetadata::class)->forPost($post);

    foreach (['facebook', 'x', 'telegram', 'whatsapp', 'reddit', 'pinterest', 'email'] as $provider) {
        expect($page->query("//a[@data-testid='share-{$provider}']/@href")->item(0)?->nodeValue)
            ->toBe(app(ShareUrlBuilder::class)->build($provider, $metadata), "share-{$provider}");
    }
});
