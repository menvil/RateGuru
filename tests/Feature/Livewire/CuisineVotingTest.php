<?php

use App\Enums\CuisineType;
use App\Livewire\Posts\CuisineVoting;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('can render category voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Category A')
        ->assertSee('Category B')
        ->assertSee('Category C')
        ->assertSee('Category D')
        ->assertSee('Other');
});

it('does not record a category vote when an unauthenticated guest votes', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->assertSee('Guests cannot vote on category.');

    $this->assertDatabaseCount('cuisine_votes', 0);
});

it('prevents category voting on an own post before attempting to vote', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertDontSee('You cannot vote on your own post.')
        ->assertSee('disabled', false);
});

it('silently blocks own post category vote attempt without displaying the error message', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->assertSet('error', '')
        ->assertDontSee('You cannot vote on your own post.');
});

it('records category option A vote when clicked', function () {
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

it('does not change category vote through the component after first vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->call('vote', CuisineType::Mexican->value);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);
});

it('does not render inline category distribution after the current user votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->for($post)->create(['user_id' => $user->id, 'cuisine' => CuisineType::Italian]);
    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Italian]);
    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Asian]);

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="cuisine-distribution-panel"', false)
        ->assertSee('Category A')
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('Category B')
        ->assertDontSee('Vote to reveal results.');
});

it('hides category distribution before the current user votes', function () {
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->for($post)->create(['cuisine' => CuisineType::Italian]);

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="cuisine-distribution-panel"', false)
        ->assertDontSee('Vote to reveal results.');
});

it('renders category chips with selected and focus states', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian,
    ]);

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('data-state="active"', false)
        ->assertSee('bg-rg-accentSoft', false)
        ->assertSee('focus-visible:ring-rg-accent', false);
});

it('renders category chips with reference feed sizing', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('flex flex-wrap gap-2', false)
        ->assertSee('inline-flex h-8 min-w-11', false)
        ->assertSee('rounded-rgSm border px-2.5 text-xs font-semibold', false)
        ->assertSee('border-rg-border2 bg-transparent text-rg-text2 hover:bg-rg-card2', false);
});

it('keeps zero category distribution out of the inline voting controls', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian,
    ]);

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="cuisine-distribution-panel"', false)
        ->assertSee('aria-pressed="true"', false);
});

it('refreshes category selected state after vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertDontSee('Vote to reveal results.')
        ->call('vote', CuisineType::Italian->value)
        ->assertSee('Category A')
        ->assertSee('aria-pressed="true"', false);
});

it('keeps category distribution locked after attempted vote change', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->call('vote', CuisineType::Mexican->value)
        ->assertSee('Category A')
        ->assertSee('aria-pressed="true"', false);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);
});
