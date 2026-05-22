<?php

use App\Actions\Moderation\ApprovePostAction;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use Illuminate\Support\Facades\Notification;

it('notifies post owner when post is approved', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()
        ->for($owner)
        ->pending()
        ->create();

    app(ApprovePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Valid post.'
    );

    Notification::assertSentTo(
        $owner,
        PostApprovedNotification::class
    );
});

it('does not notify when post approval fails', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $normalUser = User::factory()->create();

    $post = Post::factory()
        ->for($owner)
        ->pending()
        ->create();

    try {
        app(ApprovePostAction::class)->handle(
            moderator: $normalUser,
            post: $post,
        );
    } catch (CannotModeratePostException) {
        // Expected.
    }

    Notification::assertNothingSent();
});
