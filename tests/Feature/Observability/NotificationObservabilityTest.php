<?php

use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Log;

it('logs followed author notification dispatch', function () {
    Log::spy();

    $author = User::factory()->create();
    $follower = User::factory()->create(['notify_followed_author_posts' => true]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Log::shouldHaveReceived('info')
        ->with('notifications.followed_author_posted.sent', Mockery::any());
});

it('logs duplicate skipped notification', function () {
    Log::spy();

    $author = User::factory()->create();
    $follower = User::factory()->create(['notify_followed_author_posts' => true]);

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $post = Post::factory()->for($author, 'user')->published()->create();

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);
    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    Log::shouldHaveReceived('info')
        ->with('notifications.followed_author_posted.duplicate_skipped', Mockery::any());
});
