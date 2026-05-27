<?php

use App\Enums\VoteType;
use App\Enums\OriginType;
use App\Models\Post;
use App\Models\User;

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

it('allows authenticated user to vote on post origin', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Origin Vote Test Post',
    ]);

    actingAs($user);

    visit(route('feed'))
        ->assertSee('Browser Origin Vote Test Post')
        ->click("[data-testid=\"origin-vote-homemade-{$post->id}\"]")
        ->assertAttribute("[data-testid=\"origin-vote-homemade-{$post->id}\"]", 'aria-pressed', 'true');

    $this->assertDatabaseHas('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
        'origin' => OriginType::Homemade->value,
    ]);
});
