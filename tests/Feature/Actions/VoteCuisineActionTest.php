<?php

use App\Actions\Votes\VoteCuisineAction;
use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;

it('allows user to vote italian cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);

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
