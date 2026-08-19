<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Models\Comment;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use App\Services\Notifications\LifecycleSafeDatabaseNotifier;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\File;

/*
 * Identity-bearing database notifications are serialized against
 * anonymization: identity always comes from the fresh locked source, a
 * Deleted actor or recipient produces nothing, and a notification that
 * won the race is removed by the PR-B cleanup. Exactly two outcomes.
 */

function safeSend(User $recipient, User $source): bool
{
    return app(LifecycleSafeDatabaseNotifier::class)->send(
        recipientId: (int) $recipient->id,
        identitySourceId: (int) $source->id,
        notification: function (User $fresh) {
            $post = Post::factory()->published()->create();

            return new PostCommentedNotification(
                post: $post,
                comment: Comment::factory()->create(['post_id' => $post->id]),
                actor: $fresh,
            );
        },
    );
}

it('creates nothing when the identity source is already Deleted, without building the payload', function () {
    $recipient = User::factory()->create();
    $actor = User::factory()->create();
    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($actor->id));

    $factoryRan = false;

    $sent = app(LifecycleSafeDatabaseNotifier::class)->send(
        recipientId: (int) $recipient->id,
        identitySourceId: (int) $actor->id,
        notification: function (User $fresh) use (&$factoryRan) {
            $factoryRan = true;

            throw new LogicException('factory must not run for a tombstoned source');
        },
    );

    expect($sent)->toBeFalse()
        ->and($factoryRan)->toBeFalse()
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

it('creates nothing when the recipient is already Deleted', function () {
    $recipient = User::factory()->create();
    $actor = User::factory()->create();
    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($recipient->id));

    expect(safeSend($recipient->fresh(), $actor))->toBeFalse()
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

it('never persists old PII when a stale sender context runs after anonymization', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    $staleCommenter = User::factory()->create(['username' => 'old_identity']);

    // Anonymization commits behind the stale request's back.
    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($staleCommenter->id));

    expect($staleCommenter->username)->toBe('old_identity');

    // The stale sender context resolves by id inside the notifier: the
    // locked re-read sees Deleted and nothing is created.
    $sent = app(LifecycleSafeDatabaseNotifier::class)->send(
        recipientId: (int) $author->id,
        identitySourceId: (int) $staleCommenter->id,
        notification: fn (User $fresh) => new PostCommentedNotification(
            post: $post,
            comment: Comment::factory()->create(['post_id' => $post->id]),
            actor: $fresh,
        ),
    );

    expect($sent)->toBeFalse()
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

it('removes a notification that won the race once anonymization runs', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    $commenter = User::factory()->create();

    app(AddCommentAction::class)->handle($commenter, $post, 'A perfectly fine comment');

    expect(DatabaseNotification::query()->count())->toBe(1);

    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($commenter->id));

    // PR-B cleanup matched the actor_id key: no old PII remains.
    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('produces the same happy-path payload as before through the safe notifier', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    $commenter = User::factory()->create(['username' => 'fresh_commenter']);

    app(AddCommentAction::class)->handle($commenter, $post, 'A perfectly fine comment');

    $notification = DatabaseNotification::query()->firstOrFail();
    expect($notification->notifiable_id)->toBe($author->id)
        ->and($notification->data['type'])->toBe('post_commented')
        ->and($notification->data['actor_id'])->toBe($commenter->id)
        ->and($notification->data['actor_username'])->toBe('fresh_commenter')
        ->and($notification->data['message'])->toBe('@fresh_commenter commented on your post')
        ->and($notification->data['url'])->not->toBeEmpty();
});

it('does not block living sanctioned recipients', function (string $state) {
    $recipient = User::factory()->{$state}()->create();
    $actor = User::factory()->create();

    expect(safeSend($recipient, $actor))->toBeTrue()
        ->and($recipient->notifications()->count())->toBe(1);
})->with(['limited', 'banned', 'shadowbanned']);

it('sends no follower notification with old author identity after the author tombstones', function () {
    $author = User::factory()->create(['username' => 'author_before']);
    $post = Post::factory()->published()->for($author)->create();

    $follower = User::factory()->create(['notify_followed_author_posts' => true]);
    Follow::create(['follower_id' => $follower->id, 'author_id' => $author->id]);

    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($author->id));

    app(NotifyFollowersAboutNewPostAction::class)->handle($post->fresh());

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('sends follower notifications with the fresh author identity on the happy path', function () {
    $author = User::factory()->create(['username' => 'live_author']);
    $post = Post::factory()->published()->for($author)->create();

    $follower = User::factory()->create(['notify_followed_author_posts' => true]);
    Follow::create(['follower_id' => $follower->id, 'author_id' => $author->id]);

    app(NotifyFollowersAboutNewPostAction::class)->handle($post);

    $notification = DatabaseNotification::query()->firstOrFail();
    expect($notification->notifiable_id)->toBe($follower->id)
        ->and($notification->data['author_id'])->toBe($author->id)
        ->and($notification->data['author_username'])->toBe('live_author')
        ->and($notification->data['message'])->toBe('@live_author posted '.$post->title);
});

it('keeps identity-bearing notifications out of direct notify() call sites', function () {
    // Narrow guard: the three identity-bearing notification classes must
    // never be persisted via a direct ->notify(new ...) — construction
    // belongs inside LifecycleSafeDatabaseNotifier factories.
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->filter(fn (string $path) => preg_match(
            '/->notify\(\s*new\s+(PostCommentedNotification|PostApprovedNotification|FollowedAuthorPostedNotification)/',
            (string) file_get_contents(base_path($path)),
        ) === 1)
        ->values();

    expect($offenders->all())->toBe([]);
});
