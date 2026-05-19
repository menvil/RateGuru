<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use Livewire\Livewire;

it('can render post drawer component', function () {
    Livewire::test(PostDrawer::class)
        ->assertStatus(200);
});

it('renders post voting component in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-voting"', false);
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

it('renders not found state for missing post', function () {
    Livewire::test(PostDrawer::class, ['postId' => 999999])
        ->assertSee('Post not found')
        ->assertSee('This post is unavailable');
});

it('renders not found state for pending post', function () {
    $post = Post::factory()->pending()->create([
        'title' => 'Pending Hidden',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Post not found')
        ->assertDontSee('Pending Hidden');
});

it('has drawer loading state markup', function () {
    Livewire::test(PostDrawer::class)
        ->assertSee('data-testid="post-drawer-loading"', false)
        ->assertSee('wire:loading', false);
});

it('renders the comments section in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Comments')
        ->assertSee('data-testid="comments-section"', false)
        ->assertDontSee('Comments will appear here');
});

it('renders drawer vote summary', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
        'homemade_votes_count' => 7,
        'restaurant_votes_count' => 5,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Score')
        ->assertSee('9')
        ->assertSee('Homemade')
        ->assertSee('7')
        ->assertSee('Restaurant')
        ->assertSee('5');
});

it('renders drawer author metadata', function () {
    $user = \App\Models\User::factory()->create([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->for($user)->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Demo Chef')
        ->assertSee('@demo_chef');
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
        ->assertSee('Score')
        ->assertSee('Comments');
});

it('renders image placeholder when drawer post has no image', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Image preview');
});

it('renders origin voting panel in drawer', function () {
    $post = \App\Models\Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('post-drawer-origin-voting', false)
        ->assertSee('Homemade')
        ->assertSee('Restaurant');
});

it('renders cuisine voting buttons in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-cuisine-voting"', false)
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other');
});
