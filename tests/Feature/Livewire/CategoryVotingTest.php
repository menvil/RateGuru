<?php

use App\Livewire\Posts\CategoryVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Livewire\Livewire;

it('renders category voting component using generic rating configuration', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="category-voting"', false)
        ->assertSee('Category A')
        ->assertSee('Category B')
        ->assertSee('Category C');
});

it('stores category votes in the generic rating votes table', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $post = Post::factory()->published()->create();
    $user = User::factory()->create();
    $category = RatingGroup::query()->where('key', 'category')->firstOrFail();
    $option = $category->options()->active()->firstOrFail();

    Livewire::actingAs($user)
        ->test(CategoryVoting::class, ['postId' => $post->id])
        ->call('vote', $option->id)
        ->assertDispatched('category-voted');

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $category->id,
        'rating_option_id' => $option->id,
    ]);

    $this->assertDatabaseCount('cuisine_votes', 0);
});
