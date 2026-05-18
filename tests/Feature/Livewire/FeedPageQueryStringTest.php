<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
use App\Models\Tag;
use Livewire\Livewire;

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

it('has empty default search', function () {
    $component = Livewire::test(FeedPage::class);

    expect($component->instance()->search)->toBe('');
});

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

it('hydrates sort from query string', function () {
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

    $this->get('/?sort=top')
        ->assertSeeInOrder(['High Score', 'Low Score']);
});

it('sets sort property from query string', function () {
    Livewire::withQueryParams(['sort' => 'hot'])
        ->test(FeedPage::class)
        ->assertSet('sort', 'hot');
});

it('falls back to newest for invalid sort in query string', function () {
    Livewire::withQueryParams(['sort' => 'invalid'])
        ->test(FeedPage::class)
        ->assertSet('sort', 'newest');
});
