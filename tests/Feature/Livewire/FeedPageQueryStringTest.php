<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
use App\Models\Tag;
use Livewire\Livewire;

// RG-199: URL query string sync for search
it('hydrates search from query string', function () {
    Post::factory()->published()->create(['title' => 'Homemade Pasta']);
    Post::factory()->published()->create(['title' => 'Chocolate Cake']);

    $this->get('/?search=pasta')
        ->assertSee('Homemade Pasta')
        ->assertDontSee('Chocolate Cake');
});

it('sets search property from query string', function () {
    Livewire::withQueryParams(['search' => 'pasta'])
        ->test(FeedPage::class)
        ->assertSet('search', 'pasta');
});

it('does not add search to URL when empty', function () {
    $component = Livewire::test(FeedPage::class);

    expect($component->instance()->search)->toBe('');
});

// RG-200: URL query string sync for category
it('hydrates category from query string', function () {
    $tag = Tag::factory()->create(['slug' => 'pasta']);

    $matching = Post::factory()->published()->create(['title' => 'Pasta Dish']);
    $matching->tags()->attach($tag);

    Post::factory()->published()->create(['title' => 'Cake']);

    $this->get('/?category=pasta')
        ->assertSee('Pasta Dish')
        ->assertDontSee('Cake');
});

it('sets category property from query string', function () {
    Livewire::withQueryParams(['category' => 'pasta'])
        ->test(FeedPage::class)
        ->assertSet('category', 'pasta');
});
