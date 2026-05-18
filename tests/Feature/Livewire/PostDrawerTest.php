<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use Livewire\Livewire;

it('can render post drawer component', function () {
    Livewire::test(PostDrawer::class)
        ->assertStatus(200);
});

it('renders selected published post', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});

it('does not render hidden post', function () {
    $post = Post::factory()->hidden()->create([
        'title' => 'Hidden Dish',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('Hidden Dish')
        ->assertSee('Post not found');
});

it('renders large post image in drawer', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('/storage/posts/1/dish.jpg')
        ->assertSee('alt="Dish"', false);
});

it('renders drawer post title and description', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});

it('does not break when drawer post description is missing', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'description' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Dish')
        ->assertDontSee('Creamy pasta');
});

it('renders image placeholder when drawer post has no image', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Image preview');
});
