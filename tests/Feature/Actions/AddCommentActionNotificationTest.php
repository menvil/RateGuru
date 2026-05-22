<?php

use App\Actions\Comments\AddCommentAction;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
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

    Notification::assertNotSentTo(
        $commenter,
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

it('does not fail comment creation when notification delivery fails', function () {
    $postOwner = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::factory()
        ->for($postOwner)
        ->published()
        ->create();

    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new \RuntimeException('Notification storage failed.');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new \RuntimeException('Notification storage failed.');
        }
    });

    $comment = app(AddCommentAction::class)->handle(
        user: $commenter,
        post: $post,
        body: 'Looks good.'
    );

    expect($comment)->toBeInstanceOf(Comment::class);

    $this->assertDatabaseHas('comments', [
        'id' => $comment->id,
        'post_id' => $post->id,
        'user_id' => $commenter->id,
    ]);
});
