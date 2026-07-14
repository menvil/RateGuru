<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;

it('has posts show route', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk();
});

it('renders post voting component on post show page', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-footer"', false)
        ->assertSee('data-testid="post-show-voting"', false)
        ->assertSee('data-testid="post-show-rating-controls"', false)
        ->assertDontSee('data-testid="post-show-side-panel"', false)
        ->assertDontSee('lg:grid-cols-[minmax(0,1fr)_360px]', false);
});

it('renders published post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});

it('renders post hero image', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('/storage/posts/1/dish.jpg')
        ->assertSee('alt="Dish"', false)
        ->assertSee('data-testid="post-show-image-open"', false)
        ->assertSee('data-testid="post-fullscreen-image"', false);
});

it('renders post show content in feed card order', function () {
    $user = User::factory()->create([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->for($user)->create([
        'title' => 'Ordered Dish',
        'description' => 'Description should sit below title',
        'image_url' => '/storage/posts/1/ordered.jpg',
    ]);

    $response = $this->get(route('posts.show', $post))->assertOk();
    $html = $response->getContent();

    $metaPosition = strpos($html, 'data-testid="post-show-meta"');
    $titlePosition = strpos($html, '<h1', $metaPosition);
    $descriptionPosition = strpos($html, 'Description should sit below title', $titlePosition);
    $imagePosition = strpos($html, 'data-testid="post-show-hero"', $descriptionPosition);

    expect(strpos($html, 'Demo Chef', $metaPosition))->toBeLessThan($titlePosition);
    expect($titlePosition)->toBeLessThan($descriptionPosition);
    expect($descriptionPosition)->toBeLessThan($imagePosition);
});

it('renders hero image placeholder when image is missing', function () {
    $post = Post::factory()->published()->create([
        'image_url' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Image preview');
});

it('renders post metadata', function () {
    $user = User::factory()->create([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $tag = Tag::factory()->create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $post = Post::factory()->published()->for($user)->create([
        'source_url' => 'https://example.com/original',
    ]);

    $post->tags()->attach($tag);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Demo Chef')
        ->assertSee('@demo_chef')
        ->assertSee('Pasta')
        ->assertSee('Source');
});

it('renders the comments section on post page', function () {
    $post = Post::factory()->published()->create([
        'comments_count' => 3,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Comments')
        ->assertSee('3')
        ->assertSee('data-testid="comments-section"', false)
        ->assertDontSee('Comments will appear here');
});

it('renders share button in footer on post page', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-share-btn"', false)
        ->assertDontSee('data-testid="post-show-share"', false);
});

it('hides save action from guests on post page', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="save-post-button"', false)
        ->assertDontSee('>Save<', false);
});

it('does not render related posts placeholder', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('Related posts')
        ->assertDontSee('Related dishes will appear here');
});

it('renders seo title for post page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<title>Homemade Carbonara · '.config('app.name', 'RateGuru').'</title>', false);
});

it('renders open graph metadata for post page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('property="og:title"', false)
        ->assertSee('content="Homemade Carbonara · RateGuru"', false)
        ->assertSee('property="og:description"', false)
        ->assertSee('Creamy pasta with pepper', false)
        ->assertSee('property="og:type"', false)
        ->assertSee('property="og:url"', false)
        ->assertSee('property="og:image"', false);
});

it('does not show hidden post to guest', function () {
    $post = Post::factory()->hidden()->create([
        'title' => 'Hidden Dish',
    ]);

    $this->get(route('posts.show', $post))
        ->assertNotFound()
        ->assertDontSee('Hidden Dish');
});

it('does not show pending post to normal user', function () {
    $user = User::factory()->create();

    $post = Post::factory()->pending()->create([
        'title' => 'Pending Dish',
    ]);

    $this->actingAs($user)
        ->get(route('posts.show', $post))
        ->assertNotFound()
        ->assertDontSee('Pending Dish');
});

it('does not show rejected post to normal user', function () {
    $user = User::factory()->create();

    $post = Post::factory()->rejected()->create([
        'title' => 'Rejected Dish',
    ]);

    $this->actingAs($user)
        ->get(route('posts.show', $post))
        ->assertNotFound()
        ->assertDontSee('Rejected Dish');
});
