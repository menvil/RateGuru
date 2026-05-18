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
