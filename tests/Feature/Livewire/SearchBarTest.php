<?php

use App\Livewire\Feed\SearchBar;
use Livewire\Livewire;

it('can render search bar component', function () {
    Livewire::test(SearchBar::class)
        ->assertStatus(200);
});

it('renders search input', function () {
    Livewire::test(SearchBar::class)
        ->assertSee('data-testid="search-input"', false);
});

it('has default empty search', function () {
    Livewire::test(SearchBar::class)
        ->assertSet('search', '');
});

it('uses debounced live binding on search input', function () {
    Livewire::test(SearchBar::class)
        ->assertSee('wire:model.live.debounce.500ms', false);
});
