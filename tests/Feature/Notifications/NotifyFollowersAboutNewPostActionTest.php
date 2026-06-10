<?php

use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use Illuminate\Support\Facades\Notification;

it('notifies followers when author publishes post', function () {
    Notification::fake();

    $author = User::factory()->create();
    $follower = User::factory()->create([
        'notify_followed_author_posts' => true,
    ]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Notification::assertSentTo($follower, FollowedAuthorPostedNotification::class);
});

it('does not notify follower when preference disabled', function () {
    Notification::fake();

    $author = User::factory()->create();
    $follower = User::factory()->create([
        'notify_followed_author_posts' => false,
    ]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Notification::assertNotSentTo($follower, FollowedAuthorPostedNotification::class);
});

it('does not notify author about own post', function () {
    Notification::fake();

    $author = User::factory()->create();
    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Notification::assertNotSentTo($author, FollowedAuthorPostedNotification::class);
});

it('does not notify non-followers', function () {
    Notification::fake();

    $author = User::factory()->create();
    $nonFollower = User::factory()->create(['notify_followed_author_posts' => true]);
    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Notification::assertNotSentTo($nonFollower, FollowedAuthorPostedNotification::class);
});

it('does not send notification for non-published post', function () {
    Notification::fake();

    $author = User::factory()->create();
    $follower = User::factory()->create(['notify_followed_author_posts' => true]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->pending()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Notification::assertNotSentTo($follower, FollowedAuthorPostedNotification::class);
});
