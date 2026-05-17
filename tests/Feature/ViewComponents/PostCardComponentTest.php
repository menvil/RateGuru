<?php

use App\Models\Post;
use Illuminate\Support\Facades\Blade;

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

it('renders image placeholder when image url is missing', function () {
    $post = Post::factory()->published()->make(['image_url' => null]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Food image');
});

it('renders post author area', function () {
    $user = \App\Models\User::factory()->make([
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
