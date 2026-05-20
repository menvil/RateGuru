<?php

use App\Livewire\Moderation\InlinePostModeration;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('can render inline post moderation component', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertStatus(200);
});
