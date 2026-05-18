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

it('has scrollable container for tabs', function () {
    Livewire::test(CategoryTabs::class)
        ->assertSee('data-testid="category-tabs"', false);
});
