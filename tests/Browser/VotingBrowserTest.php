<?php

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;

use function Pest\Laravel\actingAs;

it('allows authenticated user to upvote a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Upvote Test Post',
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    actingAs($user);

    visit(route('feed'))
        ->assertSee('Browser Upvote Test Post')
        ->click("[data-testid=\"post-upvote-button-{$post->id}\"]")
        ->assertSeeIn("[data-testid=\"post-upvote-count-{$post->id}\"]", '1')
        ->assertAttribute("[data-testid=\"post-upvote-button-{$post->id}\"]", 'aria-pressed', 'true');

    $this->assertDatabaseHas('post_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
        'type' => VoteType::Up->value,
    ]);
});

it('allows authenticated user to vote on a post type option', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Type Vote Test Post',
    ]);
    $type = RatingGroup::query()->where('key', 'type')->firstOrFail();
    $option = $type->options()->active()->firstOrFail();

    actingAs($user);

    visit(route('feed'))
        ->assertSee('Browser Type Vote Test Post')
        ->click("[data-testid=\"rating-option-{$post->id}-{$option->id}\"]")
        ->assertPresent("[data-testid=\"rating-option-{$post->id}-results\"]");

    $this->assertDatabaseHas('rating_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
        'rating_group_id' => $type->id,
        'rating_option_id' => $option->id,
    ]);
});
