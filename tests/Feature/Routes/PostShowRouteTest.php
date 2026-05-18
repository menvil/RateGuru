<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

it('has posts show route', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk();
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
        ->assertSee('alt="Dish"', false);
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
    $post = Post::factory()->rejected()->create([
        'title' => 'Rejected Dish',
    ]);

    $this->get(route('posts.show', $post))
        ->assertNotFound()
        ->assertDontSee('Rejected Dish');
});
