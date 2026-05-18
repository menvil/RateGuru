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

it('calls cuisine vote action when italian button is clicked', function () {
    $user = \App\Models\User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', \App\Enums\CuisineType::Italian->value);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => \App\Enums\CuisineType::Italian->value,
    ]);
});

it('can change cuisine vote through the component', function () {
    $user = \App\Models\User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', \App\Enums\CuisineType::Italian->value)
        ->call('vote', \App\Enums\CuisineType::Mexican->value);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => \App\Enums\CuisineType::Mexican->value,
    ]);

    expect(\App\Models\CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);
});
