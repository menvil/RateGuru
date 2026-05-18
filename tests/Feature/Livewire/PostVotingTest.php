<?php

use App\Livewire\Posts\PostVoting;
use App\Models\Post;
use Livewire\Livewire;

it('can render post voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Up')
        ->assertSee('Down');
});
