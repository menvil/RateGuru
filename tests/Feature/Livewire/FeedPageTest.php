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

// RG-187: SearchBar updates feed search state
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

// RG-191: category selection updates feed filter
it('has category state on feed page', function () {
    Livewire::test(FeedPage::class)
        ->assertSet('category', null);
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
