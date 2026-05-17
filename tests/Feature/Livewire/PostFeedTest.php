<?php

use App\Livewire\Feed\PostFeed;
use App\Models\Post;
use Livewire\Livewire;

it('can render post feed component', function () {
    Livewire::test(PostFeed::class)
        ->assertStatus(200);
});

it('shows published post title', function () {
    Post::factory()->published()->create(['title' => 'Homemade Carbonara']);

    Livewire::test(PostFeed::class)
        ->assertSee('Homemade Carbonara');
});

it('does not show pending post title', function () {
    Post::factory()->pending()->create(['title' => 'Pending Dish']);

    Livewire::test(PostFeed::class)
        ->assertDontSee('Pending Dish');
});

it('renders post cards using the post card component', function () {
    Post::factory()->published()->create(['title' => 'Homemade Carbonara']);

    Livewire::test(PostFeed::class)
        ->assertSee('data-testid="post-card"', false)
        ->assertSee('Homemade Carbonara');
});
