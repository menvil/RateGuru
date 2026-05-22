<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Blade;

it('renders post voting component in post card', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('post-card-voting');
});

it('renders post card title', function () {
    $post = Post::factory()->published()->make(['title' => 'Homemade Carbonara']);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Homemade Carbonara');
});

it('renders post image when image url exists', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('/storage/posts/1/dish.jpg');
});

it('renders post image from image path when image url is missing', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_path' => 'posts/1/dish.jpg',
        'image_url' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('/storage/posts/1/dish.jpg');
});

it('renders image placeholder when image url is missing', function () {
    $post = Post::factory()->published()->make([
        'image_path' => null,
        'image_url' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Food image');
});

it('renders post title and description', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('Homemade Carbonara')
        ->toContain('Creamy pasta with pepper');
});

it('does not break when description is missing', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'description' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Dish');
});

it('renders post stats area', function () {
    $post = Post::factory()->published()->make([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
        'comments_count' => 5,
        'homemade_votes_count' => 7,
        'restaurant_votes_count' => 4,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('9')
        ->toContain('5 comments')
        ->toContain('Homemade')
        ->toContain('Restaurant');
});

it('renders post author area', function () {
    $user = User::factory()->make([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->make(['title' => 'Dish']);
    $post->setRelation('user', $user);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('Demo Chef')
        ->toContain('@demo_chef');
});

it('post card dispatches open drawer event with post id', function () {
    $post = Post::factory()->published()->make();
    $post->id = 123;

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain("open-post-drawer', { postId: 123 }");
});

it('renders report button in post card menu for persisted posts', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('data-testid="post-card-report"')
        ->toContain('Report');
});

it('does not render report button for unsaved post preview', function () {
    $post = Post::factory()->published()->make(['title' => 'Preview dish']);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)
        ->toContain('Preview dish')
        ->not->toContain('data-testid="post-card-report"');
});

it('renders origin voting component in post card for persisted posts', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('post-card-origin-voting');
});

it('renders origin badges without breaking on unsaved post', function () {
    $post = Post::factory()->published()->make([
        'homemade_votes_count' => 2,
        'restaurant_votes_count' => 1,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Homemade 2');
    expect($html)->toContain('Restaurant 1');
    // Unsaved posts must not render the interactive Livewire origin component.
    expect($html)->not->toContain('post-card-origin-voting');
});
