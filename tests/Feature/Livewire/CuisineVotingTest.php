<?php

use App\Livewire\Posts\CuisineVoting;
use App\Models\Post;
use Livewire\Livewire;

it('can render cuisine voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other');
});
