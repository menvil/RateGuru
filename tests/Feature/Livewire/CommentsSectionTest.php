<?php

use App\Livewire\Comments\CommentsSection;
use App\Models\Post;
use Livewire\Livewire;

it('can render comments section component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CommentsSection::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('data-testid="comments-section"', false);
});
