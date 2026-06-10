<?php

use App\Actions\Follows\UnfollowAuthorAction;
use App\Models\Follow;
use App\Models\User;

it('allows user to unfollow author', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    app(UnfollowAuthorAction::class)->handle($follower, $author);

    $this->assertDatabaseMissing('follows', [
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);
});

it('is idempotent when unfollowing an author not followed', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();

    app(UnfollowAuthorAction::class)->handle($follower, $author);

    expect(Follow::count())->toBe(0);
});

it('does not remove unrelated follow rows', function () {
    $follower = User::factory()->create();
    $author = User::factory()->create();
    $otherAuthor = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $otherAuthor->id,
    ]);

    app(UnfollowAuthorAction::class)->handle($follower, $author);

    expect(Follow::count())->toBe(1);
});
