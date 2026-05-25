<?php

use App\Livewire\Feed\SortDropdown;
use Livewire\Livewire;

it('can render sort dropdown component', function () {
    Livewire::test(SortDropdown::class)
        ->assertStatus(200);
});

it('shows all sort options', function () {
    Livewire::test(SortDropdown::class)
        ->assertSee('Newest')
        ->assertSee('Top')
        ->assertSee('Hot');
});

it('has default sort of newest', function () {
    Livewire::test(SortDropdown::class)
        ->assertSet('sort', 'newest');
});

it('accepts initial sort prop', function () {
    Livewire::test(SortDropdown::class, ['sort' => 'top'])
        ->assertSet('sort', 'top');
});

it('marks the active sort option with aria-pressed', function () {
    Livewire::test(SortDropdown::class, ['sort' => 'top'])
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('bg-rg-accent text-rg-onAccent', false);
});

it('has a testid for the dropdown container', function () {
    Livewire::test(SortDropdown::class)
        ->assertSee('data-testid="sort-dropdown"', false);
});

it('has alpine x-data directive for dropdown behavior', function () {
    Livewire::test(SortDropdown::class)
        ->assertSee('x-data', false);
});

it('has alpine x-show for dropdown menu visibility', function () {
    Livewire::test(SortDropdown::class)
        ->assertSee('x-show', false);
});

it('has click outside handler to close dropdown', function () {
    Livewire::test(SortDropdown::class)
        ->assertSee('@click.outside', false);
});
