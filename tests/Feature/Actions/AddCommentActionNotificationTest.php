<?php

use App\Actions\Comments\AddCommentAction;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use Illuminate\Support\Facades\Notification;

it('notifies post owner when another user comments on their post', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::factory()
        ->for($postOwner)
        ->published()
        ->create();

    app(AddCommentAction::class)->handle(
        user: $commenter,
        post: $post,
        body: 'Looks good.'
    );

    Notification::assertSentTo(
        $postOwner,
        PostCommentedNotification::class
    );
});

it('does not notify post owner when they comment on their own post', function () {
    Notification::fake();

    $owner = User::factory()->create();

    $post = Post::factory()
        ->for($owner)
        ->published()
        ->create();

    app(AddCommentAction::class)->handle(
        user: $owner,
        post: $post,
        body: 'My own comment.'
    );

    Notification::assertNothingSent();
});
