<?php

use App\Enums\OriginType;
use App\Livewire\Posts\OriginVoting;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('can render origin voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Homemade')
        ->assertSee('Restaurant');
});

it('calls origin vote action when homemade button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', OriginType::Homemade->value)
        ->assertDispatched('origin-voted');

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);
});

it('calls origin vote action when restaurant button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', OriginType::Restaurant->value)
        ->assertDispatched('origin-voted');

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Restaurant->value,
    ]);
});

it('shows error when guest tries to vote origin', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', OriginType::Homemade->value)
        ->assertSee('Guests cannot vote on origin.');

    expect(OriginVote::query()->count())->toBe(0);
});

it('renders origin distribution bar', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 3,
        'restaurant_votes_count' => 1,
    ]);

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('75%')
        ->assertSee('25%')
        ->assertSee('origin-distribution-bar', false);
});

it('renders origin voting pills with selected and focus states', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    OriginVote::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade,
    ]);

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('data-state="active"', false)
        ->assertSee('bg-rg-accentSoft', false)
        ->assertSee('focus-visible:ring-rg-accent', false);
});

it('renders zero origin distribution safely', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('0%')
        ->assertSee('origin-distribution-bar', false);
});

it('refreshes origin counters after vote', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('Homemade 0%')
        ->call('vote', OriginType::Homemade->value)
        ->assertSee('Homemade 100%')
        ->assertDispatched('origin-voted')
        ->call('vote', OriginType::Restaurant->value)
        ->assertSee('Restaurant 100%')
        ->assertSee('Homemade 0%');
});
