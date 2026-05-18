<?php

use App\Models\Post;

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
