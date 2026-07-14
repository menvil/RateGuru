<?php

use App\Actions\Moderation\ApprovePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
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

    Notification::assertNotSentTo(
        $moderator,
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

    $this->expectException(CannotModeratePostException::class);

    try {
        app(ApprovePostAction::class)->handle(
            moderator: $normalUser,
            post: $post,
        );
    } finally {
        Notification::assertNothingSent();
    }
});

it('does not fail approval when notification delivery fails', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()
        ->for($owner)
        ->pending()
        ->create();

    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('Notification storage failed.');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('Notification storage failed.');
        }
    });

    app(ApprovePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Valid post.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
