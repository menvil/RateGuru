<?php

use App\Livewire\Posts\OriginVoting;
use App\Models\Post;
use Livewire\Livewire;

it('can render origin voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Homemade')
        ->assertSee('Restaurant');
});
