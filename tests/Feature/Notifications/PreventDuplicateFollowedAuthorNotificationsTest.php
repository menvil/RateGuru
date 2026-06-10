<?php

use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;

it('does not send duplicate followed author notifications for same post', function () {
    $author = User::factory()->create();
    $follower = User::factory()->create(['notify_followed_author_posts' => true]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);
    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    $count = $follower->notifications()
        ->where('type', FollowedAuthorPostedNotification::class)
        ->get()
        ->filter(fn ($n) => ($n->data['post_id'] ?? null) === $post->id)
        ->count();

    expect($count)->toBe(1);
});

it('still sends first notification', function () {
    $author = User::factory()->create();
    $follower = User::factory()->create(['notify_followed_author_posts' => true]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    $count = $follower->notifications()
        ->where('type', FollowedAuthorPostedNotification::class)
        ->count();

    expect($count)->toBe(1);
});
