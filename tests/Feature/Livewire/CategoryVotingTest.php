<?php

use App\Enums\CuisineType;
use App\Livewire\Posts\CategoryVoting;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('renders category voting component using legacy category storage behavior', function () {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="category-voting"', false)
        ->assertSee('Category A')
        ->assertSee('Category B')
        ->assertSee('Category C')
        ->assertSee('Category D');
});

it('records category option votes through the legacy category storage', function () {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->assertDispatched('category-voted');

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);
});
