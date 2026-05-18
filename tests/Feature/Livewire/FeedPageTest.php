<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
use App\Models\Tag;
use Livewire\Livewire;

it('can render feed page component', function () {
    Livewire::test(FeedPage::class)
        ->assertStatus(200);
});

it('renders the feed page shell', function () {
    Livewire::test(FeedPage::class)
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});

it('has search state on feed page', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('search', '');
});

it('filters feed results when search state changes', function () {
    Post::factory()->published()->create(['title' => 'Homemade Pasta']);
    Post::factory()->published()->create(['title' => 'Chocolate Cake']);

    Livewire::test(FeedPage::class)
        ->set('search', 'pasta')
        ->assertSee('Homemade Pasta')
        ->assertDontSee('Chocolate Cake');
});

it('has category state on feed page', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('category', null);
});

it('has sort state on feed page with default newest', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('sort', 'newest');
});

it('sorts feed when sort is changed to top', function () {
    Post::factory()->published()->create([
        'title' => 'Low Score',
        'upvotes_count' => 1,
        'downvotes_count' => 0,
        'published_at' => now()->subMinutes(5),
    ]);

    Post::factory()->published()->create([
        'title' => 'High Score',
        'upvotes_count' => 10,
        'downvotes_count' => 0,
        'published_at' => now()->subMinutes(10),
    ]);

    Livewire::test(FeedPage::class)
        ->set('sort', 'top')
        ->assertSeeInOrder(['High Score', 'Low Score']);
});

it('selects post for drawer on feed page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Drawer Dish',
    ]);

    Livewire::test(FeedPage::class)
        ->call('openPostDrawer', $post->id)
        ->assertSet('selectedPostId', $post->id)
        ->assertDispatched('post-drawer-opened');
});

it('clears selected post when drawer is closed', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(FeedPage::class)
        ->call('openPostDrawer', $post->id)
        ->call('closePostDrawer')
        ->assertSet('selectedPostId', null);
});

it('filters feed when category is selected', function () {
    $pasta = Tag::factory()->create(['slug' => 'pasta']);
    $dessert = Tag::factory()->create(['slug' => 'dessert']);

    $matching = Post::factory()->published()->create(['title' => 'Pasta Dish']);
    $matching->tags()->attach($pasta);

    $other = Post::factory()->published()->create(['title' => 'Cake']);
    $other->tags()->attach($dessert);

    Livewire::test(FeedPage::class)
        ->set('category', 'pasta')
        ->assertSee('Pasta Dish')
        ->assertDontSee('Cake');
});
