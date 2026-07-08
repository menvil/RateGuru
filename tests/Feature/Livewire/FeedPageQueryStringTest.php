<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
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

it('hydrates origin from query string', function () {
    seedFeedFilterGroups();

    Post::factory()->published()->create([
        'title' => 'Home Dish',
        'origin_truth' => OriginType::Homemade,
    ]);

    Post::factory()->published()->create([
        'title' => 'Restaurant Dish',
        'origin_truth' => OriginType::Restaurant,
    ]);

    $this->get('/?origin=homemade')
        ->assertSee('Home Dish')
        ->assertDontSee('Restaurant Dish');
});

it('sets origin property from query string', function () {
    seedFeedFilterGroups();

    Livewire::withQueryParams(['origin' => 'restaurant'])
        ->test(FeedPage::class)
        ->assertSet('origin', ['restaurant']);
});

it('hydrates multiple origin filters from query string', function () {
    Post::factory()->published()->create([
        'title' => 'Home Dish',
        'origin_truth' => OriginType::Homemade,
    ]);

    Post::factory()->published()->create([
        'title' => 'Restaurant Dish',
        'origin_truth' => OriginType::Restaurant,
    ]);

    $this->get('/?origin[0]=homemade&origin[1]=restaurant')
        ->assertSee('Home Dish')
        ->assertSee('Restaurant Dish');
});

it('hydrates cuisine from query string', function () {
    seedFeedFilterGroups();

    Post::factory()->published()->create([
        'title' => 'Italian Dish',
        'cuisine_truth' => CuisineType::Italian,
    ]);

    Post::factory()->published()->create([
        'title' => 'Mexican Dish',
        'cuisine_truth' => CuisineType::Mexican,
    ]);

    $this->get('/?cuisine=italian')
        ->assertSee('Italian Dish')
        ->assertDontSee('Mexican Dish');
});

it('sets cuisine property from query string', function () {
    seedFeedFilterGroups();

    Livewire::withQueryParams(['cuisine' => 'mexican'])
        ->test(FeedPage::class)
        ->assertSet('cuisine', ['mexican']);
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
