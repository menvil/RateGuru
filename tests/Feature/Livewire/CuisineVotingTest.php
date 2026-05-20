<?php

use App\Enums\CuisineType;
use App\Livewire\Posts\CuisineVoting;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;
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

it('does not record a cuisine vote when an unauthenticated guest votes', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->assertSee('Guests cannot vote on cuisine.');

    $this->assertDatabaseCount('cuisine_votes', 0);
});

it('calls cuisine vote action when italian button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);
});

it('can change cuisine vote through the component', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->call('vote', CuisineType::Mexican->value);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Mexican->value,
    ]);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);
});

it('renders cuisine distribution panel', function () {
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Italian]);
    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Italian]);
    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Asian]);

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="cuisine-distribution-panel"', false)
        ->assertSee('Italian')
        ->assertSee('67%')
        ->assertSee('Asian')
        ->assertSee('33%');
});

it('renders zero cuisine distribution safely', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="cuisine-distribution-panel"', false)
        ->assertSee('No cuisine votes yet');
});

it('refreshes cuisine distribution after vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('No cuisine votes yet')
        ->call('vote', CuisineType::Italian->value)
        ->assertSee('Italian')
        ->assertSee('100%');
});

it('refreshes cuisine distribution after vote change', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->call('vote', CuisineType::Mexican->value)
        ->assertSee('Mexican')
        ->assertSee('100%');

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Mexican->value,
    ]);
});
