<?php

use App\Livewire\Feed\FeedPage;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\Tag;
use Livewire\Livewire;

it('hydrates search from query string', function () {
    Post::factory()->published()->create(['title' => 'Sample Entry']);
    Post::factory()->published()->create(['title' => 'Chocolate Cake']);

    $this->get('/?search=sample')
        ->assertSee('Sample Entry')
        ->assertDontSee('Chocolate Cake');
});

it('sets search property from query string', function () {
    Livewire::withQueryParams(['search' => 'sample'])
        ->test(FeedPage::class)
        ->assertSet('search', 'sample');
});

it('has empty default search', function () {
    $component = Livewire::test(FeedPage::class);

    expect($component->instance()->search)->toBe('');
});

it('hydrates tag from query string', function () {
    $tag = Tag::factory()->create(['slug' => 'pasta']);

    $matching = Post::factory()->published()->create(['title' => 'Pasta Dish']);
    $matching->tags()->attach($tag);

    Post::factory()->published()->create(['title' => 'Cake']);

    $this->get('/?tag=pasta')
        ->assertSee('Pasta Dish')
        ->assertDontSee('Cake');
});

it('sets tag property from query string', function () {
    Livewire::withQueryParams(['tag' => 'pasta'])
        ->test(FeedPage::class)
        ->assertSet('tag', 'pasta');
});

it('hydrates category from query string', function () {
    seedFeedFilterGroups();
    $group = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $first = $group->options()->where('key', 'source_a')->firstOrFail();
    $second = $group->options()->where('key', 'source_b')->firstOrFail();

    Post::factory()->published()->create([
        'title' => 'First category post',
        'category_option_id' => $first->id,
    ]);

    Post::factory()->published()->create([
        'title' => 'Second category post',
        'category_option_id' => $second->id,
    ]);

    $this->get('/?category[0]=source_a')
        ->assertSee('First category post')
        ->assertDontSee('Second category post');
});

it('sets category property from query string', function () {
    seedFeedFilterGroups();

    Livewire::withQueryParams(['category' => 'source_b'])
        ->test(FeedPage::class)
        ->assertSet('category', ['source_b']);
});

it('hydrates multiple category filters from query string', function () {
    seedFeedFilterGroups();
    $group = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $first = $group->options()->where('key', 'source_a')->firstOrFail();
    $second = $group->options()->where('key', 'source_b')->firstOrFail();

    Post::factory()->published()->create([
        'title' => 'First category post',
        'category_option_id' => $first->id,
    ]);

    Post::factory()->published()->create([
        'title' => 'Second category post',
        'category_option_id' => $second->id,
    ]);

    Post::factory()->published()->create(['title' => 'Uncategorised post']);

    $this->get('/?category[0]=source_a&category[1]=source_b')
        ->assertSee('First category post')
        ->assertSee('Second category post')
        ->assertDontSee('Uncategorised post');
});

it('hydrates generic rating filters from query string', function () {
    seedFeedFilterGroups();
    $group = RatingGroup::query()->where('key', 'category')->firstOrFail();
    $first = $group->options()->where('key', 'category_a')->firstOrFail();
    $second = $group->options()->where('key', 'category_b')->firstOrFail();

    $matching = Post::factory()->published()->create(['title' => 'Matching answer']);
    $matching->authorAnswers()->create([
        'rating_group_id' => $group->id,
        'rating_option_id' => $first->id,
    ]);

    $other = Post::factory()->published()->create(['title' => 'Other answer']);
    $other->authorAnswers()->create([
        'rating_group_id' => $group->id,
        'rating_option_id' => $second->id,
    ]);

    $this->get('/?ratings[category][0]=category_a')
        ->assertSee('Matching answer')
        ->assertDontSee('Other answer');
});

it('sets generic rating filters from query string', function () {
    seedFeedFilterGroups();

    Livewire::withQueryParams(['ratings' => ['category' => ['category_b']]])
        ->test(FeedPage::class)
        ->assertSet('ratings', ['category' => ['category_b']]);
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
