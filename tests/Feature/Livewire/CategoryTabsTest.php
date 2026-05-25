<?php

use App\Livewire\Feed\CategoryTabs;
use App\Models\Tag;
use Livewire\Livewire;

it('can render category tabs component', function () {
    Livewire::test(CategoryTabs::class)
        ->assertStatus(200);
});

it('always renders the All tab', function () {
    Livewire::test(CategoryTabs::class)
        ->assertSee('All');
});

it('has default null selected', function () {
    Livewire::test(CategoryTabs::class)
        ->assertSet('selected', null);
});

it('renders tags from database', function () {
    Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);
    Tag::factory()->create(['name' => 'Dessert', 'slug' => 'dessert']);

    Livewire::test(CategoryTabs::class)
        ->assertSee('Pasta')
        ->assertSee('Dessert');
});

it('shows only the All tab when no tags exist', function () {
    Livewire::test(CategoryTabs::class)
        ->assertSee('All');
});

it('marks All tab as aria-selected when no category selected', function () {
    Livewire::test(CategoryTabs::class, ['selected' => null])
        ->assertSeeInOrder(['aria-selected="true"', 'All'], false);
});

it('marks tag tab as aria-selected when that tag is selected', function () {
    Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    Livewire::test(CategoryTabs::class, ['selected' => 'pasta'])
        ->assertSeeInOrder(['aria-selected="true"', 'Pasta'], false);
});

it('has non-scrolling container for a compact tab set', function () {
    Livewire::test(CategoryTabs::class)
        ->assertSee('data-testid="category-tabs"', false)
        ->assertDontSee('overflow-x-auto', false);
});

it('limits the inline tag tabs to five database tags', function () {
    foreach (range(1, 6) as $index) {
        Tag::factory()->create([
            'name' => 'Tag '.$index,
            'slug' => 'tag-'.$index,
        ]);
    }

    Livewire::test(CategoryTabs::class)
        ->assertSee('Tag 1')
        ->assertSee('Tag 5')
        ->assertDontSee('Tag 6');
});
