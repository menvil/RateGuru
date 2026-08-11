<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Storage;

it('renders complete opengraph and twitter metadata on post show', function () {
    config(['app.url' => 'https://rateguru.test']);

    ProjectSettings::factory()->create([
        'site_name' => 'RateGuru Community',
    ]);
    app(ProjectSettingsManager::class)->flush();

    $post = Post::factory()->published()->create([
        'title' => 'OG Test Post',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="OG Test Post">', false)
        ->assertSee('<meta property="og:site_name" content="RateGuru Community">', false)
        ->assertSee('<meta property="og:locale" content="en_US">', false)
        ->assertSee('<meta property="og:url" content="https://rateguru.test/posts/'.$post->id.'">', false)
        ->assertSee('<meta property="og:image" content="https://rateguru.test/images/og/rateguru-post-placeholder.png">', false)
        ->assertSee('<meta property="og:image:type" content="image/png">', false)
        ->assertSee('<meta property="og:image:width" content="1200">', false)
        ->assertSee('<meta property="og:image:height" content="630">', false)
        ->assertSee('<meta property="og:image:alt" content="OG Test Post">', false)
        ->assertSee('<meta property="og:image:secure_url" content="https://rateguru.test/images/og/rateguru-post-placeholder.png">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<meta name="twitter:image:alt" content="OG Test Post">', false)
        ->assertDontSee('<meta property="og:title" content="OG Test Post · RateGuru">', false);
});

it('renders canonical link tag on post show', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('rel="canonical"', false)
        ->assertSee(canonical_post_url($post), false);
});

it('uses the large twitter card with the raster fallback when post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_asset_id' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('content="summary_large_image"', false)
        ->assertSee('rateguru-post-placeholder.png', false);
});

it('uses summary_large_image twitter card when post has image', function () {
    // The asset's own disk can resolve to any absolute URL (e.g. a CDN) —
    // exercised here via a disk whose url config points off-origin. fake()
    // (not a real, unfaked disk) both isolates the write from the real
    // filesystem and satisfies PostImagePresenter::openGraph()'s
    // MediaStorage::exists() check — the url/visibility/driver config must
    // be passed directly into fake() since it otherwise drops any config
    // already set on the disk (it only ever carries over `throw`).
    Storage::fake('cdn_test', [
        'driver' => 'local',
        'url' => 'https://cdn.example.com',
        'visibility' => 'public',
    ]);

    $post = Post::factory()->published()->withImage(path: 'image.jpg', disk: 'cdn_test')->create();
    Storage::disk('cdn_test')->put('image.jpg', 'test-bytes');

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('content="summary_large_image"', false)
        ->assertSee('<meta property="og:image" content="https://cdn.example.com/image.jpg">', false)
        ->assertSee('<meta property="og:image:secure_url" content="https://cdn.example.com/image.jpg">', false);
});

it('does not emit og secure image url for an insecure external image', function () {
    Storage::fake('cdn_test', [
        'driver' => 'local',
        'url' => 'http://cdn.example.com',
        'visibility' => 'public',
    ]);

    $post = Post::factory()->published()->withImage(path: 'image.jpg', disk: 'cdn_test')->create();
    Storage::disk('cdn_test')->put('image.jpg', 'test-bytes');

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image" content="http://cdn.example.com/image.jpg">', false)
        ->assertDontSee('property="og:image:secure_url"', false);
});
