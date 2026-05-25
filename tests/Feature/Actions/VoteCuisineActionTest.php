<?php

use App\Actions\Votes\VoteCuisineAction;
use App\Enums\CuisineType;
use App\Exceptions\Votes\CannotVoteCuisineException;
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

it('allows user to vote asian cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Asian);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Asian->value,
    ]);
});

it('allows user to vote american cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::American);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::American->value,
    ]);
});

it('allows user to vote mexican cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Mexican);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Mexican->value,
    ]);
});

it('allows user to vote other cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Other);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Other->value,
    ]);
});

it('does not allow user to change cuisine vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
    app(VoteCuisineAction::class)->handle($user, $post->fresh(), CuisineType::Asian);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);

    $this->assertDatabaseMissing('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Asian->value,
    ]);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);
});

it('keeps same cuisine vote selected when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
    app(VoteCuisineAction::class)->handle($user, $post->fresh(), CuisineType::Italian);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);
});

it('does not allow guest to vote cuisine', function () {
    $post = Post::factory()->published()->create();

    try {
        app(VoteCuisineAction::class)->handle(null, $post, CuisineType::Italian);
        $this->fail('Expected CannotVoteCuisineException was not thrown.');
    } catch (CannotVoteCuisineException $e) {
        expect(CuisineVote::query()->count())->toBe(0);
    }
});

it('does not allow a non-voting user to vote cuisine', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    try {
        app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
        $this->fail('Expected CannotVoteCuisineException was not thrown.');
    } catch (CannotVoteCuisineException $e) {
        expect(CuisineVote::query()->count())->toBe(0);
    }
});

it('does not allow cuisine vote on hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    try {
        app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
        $this->fail('Expected CannotVoteCuisineException was not thrown.');
    } catch (CannotVoteCuisineException $e) {
        expect(CuisineVote::query()->count())->toBe(0);
    }
});

it('does not allow unknown cuisine vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    try {
        app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Unknown);
        $this->fail('Expected CannotVoteCuisineException was not thrown.');
    } catch (CannotVoteCuisineException $e) {
        expect(CuisineVote::query()->count())->toBe(0);
    }
});
