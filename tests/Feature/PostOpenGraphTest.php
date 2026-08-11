<?php

use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;

it('renders open graph image for post show page', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_asset_id' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('https://rateguru.test/images/og/rateguru-post-placeholder.png', false);
});

it('uses post image as open graph image when available', function () {
    // The image's absolute URL comes from Storage::disk($asset->disk)->url(),
    // which resolves against filesystems.disks.public.url (baked in at boot
    // from APP_URL) — not the runtime config('app.url') override above.
    config(['filesystems.disks.public.url' => 'https://rateguru.test/storage']);

    $post = Post::factory()->published()->withImage(path: 'posts/demo.jpg')->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('<meta property="og:image:secure_url"', false)
        ->assertSee('https://rateguru.test/storage/posts/demo.jpg', false);
});

it('renders open graph title for post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Best Pasta in Sofia',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Best Pasta in Sofia">', false)
        ->assertSee('<meta property="og:site_name" content="RateGuru">', false);
});

it('escapes open graph title content', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Pasta "Special" <script>',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('<script>alert', false)
        ->assertSee('content="Pasta &quot;Special&quot; &lt;script&gt;"', false)
        ->assertDontSee('content="Pasta &quot;Special&quot; &lt;script&gt; · RateGuru"', false);
});

it('renders open graph description for post show page', function () {
    $post = Post::factory()->published()->create([
        'description' => 'A detailed review of a handmade pasta dish in Sofia.',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:description" content="A detailed review of a handmade pasta dish in Sofia.">', false);
});

it('renders fallback open graph description when post has no description', function () {
    $post = Post::factory()->published()->create([
        'description' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('See and rate this post on RateGuru.', false);
});

it('strips html and truncates open graph description', function () {
    $post = Post::factory()->published()->create([
        'description' => '<b>'.str_repeat('Long text ', 40).'</b>',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:description" content="'.trim(str_repeat('Long text ', 16)).'">', false)
        ->assertDontSee('<b>', false);
});

/**
 * The four-state Open Graph image fallback matrix: a dedicated open_graph
 * variant is preferred, then post_detail_1920, then the master image, and
 * finally (no image at all, or a private image) the static placeholder —
 * never an auto-generated one.
 */
it('uses the open graph variant, at its exact 1200x630 dimensions, when it exists', function () {
    config(['app.url' => 'https://rateguru.test']);
    config(['filesystems.disks.public.url' => 'https://rateguru.test/storage']);

    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/demo.jpg',
    ]);
    MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'posts/demo/open_graph.jpg',
        'mime_type' => 'image/jpeg',
        'width' => 1200,
        'height' => 630,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image" content="https://rateguru.test/storage/posts/demo/open_graph.jpg">', false)
        ->assertSee('<meta property="og:image:type" content="image/jpeg">', false)
        ->assertSee('<meta property="og:image:width" content="1200">', false)
        ->assertSee('<meta property="og:image:height" content="630">', false);
});

it('falls back to post_detail_1920 when the open graph variant is missing', function () {
    config(['app.url' => 'https://rateguru.test']);
    config(['filesystems.disks.public.url' => 'https://rateguru.test/storage']);

    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/demo.jpg',
    ]);
    MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'posts/demo/post_detail_1920.jpg',
        'mime_type' => 'image/jpeg',
        'width' => 1920,
        'height' => 1280,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image" content="https://rateguru.test/storage/posts/demo/post_detail_1920.jpg">', false)
        ->assertSee('<meta property="og:image:width" content="1920">', false)
        ->assertSee('<meta property="og:image:height" content="1280">', false);
});

it('falls back to the master image when every variant is missing', function () {
    config(['app.url' => 'https://rateguru.test']);
    config(['filesystems.disks.public.url' => 'https://rateguru.test/storage']);

    $post = Post::factory()->published()->withImage(path: 'posts/demo.jpg', width: 1600, height: 900)->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image" content="https://rateguru.test/storage/posts/demo.jpg">', false)
        ->assertSee('<meta property="og:image:width" content="1600">', false)
        ->assertSee('<meta property="og:image:height" content="900">', false);
});

it('omits any post-image-derived open graph image for a private post image, falling back to the static placeholder', function () {
    config(['app.url' => 'https://rateguru.test']);

    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/private.jpg',
        'visibility' => MediaVisibility::Private,
    ]);
    MediaVariant::factory()->named(MediaVariantName::OpenGraph)->create([
        'media_asset_id' => $asset->id,
        'width' => 1200,
        'height' => 630,
    ]);
    $post = Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('https://rateguru.test/images/og/rateguru-post-placeholder.png', false)
        ->assertDontSee('private.jpg', false)
        ->assertDontSee('open_graph', false);
});
