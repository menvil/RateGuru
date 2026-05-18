<?php

use App\Enums\VoteType;
use App\Livewire\Posts\PostVoting;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('can render post voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Up')
        ->assertSee('Down');
});

it('calls vote action when up button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value)
        ->assertDispatched('post-voted');

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);
});

it('calls vote action when down button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Down->value);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Down->value,
    ]);
});
