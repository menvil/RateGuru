<?php

use App\Enums\OriginType;
use App\Livewire\Posts\SourceVoting;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('renders source voting component using legacy source storage behavior', function () {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SourceVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="source-voting"', false)
        ->assertSee('Source')
        ->assertSee('Source A')
        ->assertSee('Source B');
});

it('records source option votes through the legacy source storage', function () {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SourceVoting::class, ['postId' => $post->id])
        ->call('vote', OriginType::Homemade->value)
        ->assertDispatched('source-voted');

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);
});

it('refreshes matching source voting instances', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(SourceVoting::class, ['postId' => $post->id])
        ->dispatch('source-voted', postId: $post->id)
        ->assertOk();
});
