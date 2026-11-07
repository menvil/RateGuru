<?php

use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Exceptions\Follows\FollowerNotificationsNotDeliveredException;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\Follow;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

it('notifies nobody while following is switched off', function () {
    Notification::fake();
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);
    app(ProjectSettingsManager::class)->flush();

    $author = User::factory()->create();
    $follower = User::factory()->create(['notify_followed_author_posts' => true]);
    Follow::factory()->create(['follower_id' => $follower->id, 'author_id' => $author->id]);

    app(NotifyFollowersAboutNewPostAction::class)->handle(Post::factory()->for($author, 'user')->published()->create());

    Notification::assertNothingSent();
});

it('stops its job before the queue could hand it to another worker, which would notify followers twice', function () {
    $job = new NotifyFollowersAboutNewPostJob(1);

    foreach (['database', 'redis'] as $connection) {
        expect($job->timeout)->toBeLessThanOrEqual(config("queue.connections.{$connection}.retry_after") - 10);
    }
});

it('fails after notifying everyone else when one follower could not be notified, and the retry reaches only that one', function () {
    $author = User::factory()->create();
    [$missed, $reached] = User::factory()->count(2)->create(['notify_followed_author_posts' => true])->all();
    foreach ([$missed, $reached] as $follower) {
        Follow::factory()->create(['follower_id' => $follower->id, 'author_id' => $author->id]);
    }
    $post = Post::factory()->for($author, 'user')->published()->create();

    // The first write of $missed's notification fails, once.
    $failed = false;
    DB::listen(function (QueryExecuted $query) use (&$failed, $missed): void {
        if (! $failed && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'notifications') && in_array($missed->id, $query->bindings, false)) {
            $failed = true;

            throw new RuntimeException('The notifications table is unavailable.');
        }
    });

    expect(fn () => app(NotifyFollowersAboutNewPostAction::class)->handle($post))
        ->toThrow(FollowerNotificationsNotDeliveredException::class);

    expect($missed->notifications()->count())->toBe(0)
        ->and($reached->notifications()->count())->toBe(1);

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    expect($missed->notifications()->count())->toBe(1)
        ->and($reached->notifications()->count())->toBe(1);
});
