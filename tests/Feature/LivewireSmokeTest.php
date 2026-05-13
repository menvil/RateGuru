<?php

use App\Livewire\SmokeCounter;
use Livewire\Livewire;

it('renders the smoke counter component', function () {
    Livewire::test(SmokeCounter::class)
        ->assertSee('Livewire works');
});
