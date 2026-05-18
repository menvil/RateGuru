<?php

use App\Models\Post;
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
