<?php

use App\Livewire\Feed\PostFeed;
use Livewire\Livewire;

it('can render post feed component', function () {
    Livewire::test(PostFeed::class)
        ->assertStatus(200);
});
