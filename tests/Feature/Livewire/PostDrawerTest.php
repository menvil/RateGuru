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
