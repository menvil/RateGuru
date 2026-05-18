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

it('calls origin vote action when homemade button is clicked', function () {
    $user = \App\Models\User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', \App\Enums\OriginType::Homemade->value);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => \App\Enums\OriginType::Homemade->value,
    ]);
});

it('calls origin vote action when restaurant button is clicked', function () {
    $user = \App\Models\User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', \App\Enums\OriginType::Restaurant->value)
        ->assertDispatched('origin-voted');

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => \App\Enums\OriginType::Restaurant->value,
    ]);
});

it('shows error when guest tries to vote origin', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', \App\Enums\OriginType::Homemade->value)
        ->assertSee('Guests cannot vote on origin.');

    expect(\App\Models\OriginVote::query()->count())->toBe(0);
});
