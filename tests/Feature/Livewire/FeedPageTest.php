<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
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
