<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use Livewire\Livewire;

it('shows share buttons in post drawer for published post', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="share-buttons"', false);
});

it('shows social provider links in drawer share modal', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="share-facebook"', false)
        ->assertSee('data-testid="share-x"', false)
        ->assertSee('data-testid="share-telegram"', false);
});
