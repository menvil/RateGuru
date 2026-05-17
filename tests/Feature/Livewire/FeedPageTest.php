<?php

use App\Livewire\Feed\FeedPage;
use Livewire\Livewire;

it('can render feed page component', function () {
    Livewire::test(FeedPage::class)
        ->assertStatus(200);
});

it('renders the feed page shell', function () {
    Livewire::test(FeedPage::class)
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});
