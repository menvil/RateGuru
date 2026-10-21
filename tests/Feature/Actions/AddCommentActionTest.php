<?php

use App\Actions\Comments\AddCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

it('allows user to add comment to published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: 'Looks delicious.'
    );

    expect($comment)->toBeInstanceOf(Comment::class);
    expect($comment->exists)->toBeTrue();
    expect($comment->user_id)->toBe($user->id);
    expect($comment->post_id)->toBe($post->id);
    expect($comment->body)->toBe('Looks delicious.');

    $this->assertDatabaseHas('comments', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Looks delicious.',
    ]);
});

it('does not allow guest to add comment', function () {
    $post = Post::factory()->published()->create();

    try {
        app(AddCommentAction::class)->handle(
            user: null,
            post: $post,
            body: 'Guest comment'
        );
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('does not allow banned user to add comment', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    try {
        app(AddCommentAction::class)->handle(
            user: $user,
            post: $post,
            body: 'Banned comment'
        );
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('does not allow adding comment to hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, 'Comment on hidden post');
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('does not allow adding comment to pending post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, 'Comment on pending post');
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('does not allow adding comment to rejected post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->rejected()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, 'Comment on rejected post');
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('rejects empty comment body', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, '');
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('rejects whitespace only comment body', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, " \n\t ");
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('trims comment body before saving', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = app(AddCommentAction::class)->handle($user, $post, '  Nice  ');

    expect($comment->body)->toBe('Nice');
});

it('rejects too long comment body', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, str_repeat('a', 1001));
        $this->fail('Expected CannotCommentException was not thrown.');
    } catch (CannotCommentException $e) {
        expect(Comment::query()->count())->toBe(0);
    }
});

it('allows comment body at max length', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = app(AddCommentAction::class)->handle($user, $post, str_repeat('a', 1000));

    expect($comment->body)->toHaveLength(1000);
});

it('increments post comments count after adding comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['comments_count' => 0]);

    app(AddCommentAction::class)->handle($user, $post, 'Nice.');

    expect($post->fresh()->comments_count)->toBe(1);
});

it('sets comments count to visible comments count after adding comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['comments_count' => 99]);

    app(AddCommentAction::class)->handle($user, $post, 'Nice.');

    expect($post->fresh()->comments_count)->toBe(1);
});

it('rejects replies to unsaved parent comments', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->for($post)->make();

    expect(fn () => app(AddCommentAction::class)->handle($user, $post, 'Reply body', $parent))
        ->toThrow(CannotCommentException::class, 'Reply target is unavailable.');

    $this->assertDatabaseMissing('comments', [
        'body' => 'Reply body',
    ]);
});

it('dates a comment as it is written when no date is given, and an edit or a moderation moves its updated_at as ever', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $comment = app(AddCommentAction::class)->handle(User::factory()->create(), Post::factory()->published()->create(), 'Looks delicious.');

    expect($comment->fresh()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($comment->fresh()->updated_at->toDateTimeString())->toBe('2026-10-20 10:00:00');

    Carbon::setTestNow();
});

it('gives a comment the date it is given as its own created_at and updated_at, every check and lock as ever', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $post = Post::factory()->published()->create();
    $parent = app(AddCommentAction::class)->handle(User::factory()->create(), $post, 'First.', null, CarbonImmutable::parse('2025-06-10 10:05:00'));
    $reply = app(AddCommentAction::class)->handle(User::factory()->create(), $post, 'Second.', $parent, CarbonImmutable::parse('2025-06-10 10:12:00'));

    expect($parent->fresh()->created_at->toDateTimeString())->toBe('2025-06-10 10:05:00')
        ->and($parent->fresh()->updated_at->toDateTimeString())->toBe('2025-06-10 10:05:00')
        ->and($reply->fresh())->parent_id->toBe($parent->id)
        ->and($reply->fresh()->created_at->toDateTimeString())->toBe('2025-06-10 10:12:00')
        ->and($post->fresh()->comments_count)->toBe(2);

    // A date is no way around a check.
    expect(fn () => app(AddCommentAction::class)->handle(User::factory()->banned()->create(), $post, 'Late.', null, CarbonImmutable::parse('2025-06-10 10:30:00')))
        ->toThrow(CannotCommentException::class);

    Carbon::setTestNow();
});

it('says why a comment was refused as a reason a caller can act on, the member seeing the same message as before', function (Closure $refuse, string $reason, string $message) {
    $refusal = null;

    try {
        $refuse();
    } catch (CannotCommentException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toBe(__($message))
        ->and([
            'rate_limited' => $refusal->isRateLimited(),
            'post_unavailable' => $refusal->isPostUnavailable(),
            'reply_target_unavailable' => $refusal->isReplyTargetUnavailable(),
            'not_allowed' => $refusal->isNotAllowed(),
            'guest' => $refusal->isGuest(),
        ])->toBe(array_map(fn (string $key): bool => $key === $reason, ['rate_limited' => 'rate_limited', 'post_unavailable' => 'post_unavailable', 'reply_target_unavailable' => 'reply_target_unavailable', 'not_allowed' => 'not_allowed', 'guest' => 'guest']));
})->with([
    'a hidden post' => [fn () => app(AddCommentAction::class)->handle(User::factory()->create(), Post::factory()->hidden()->create(), 'Hi.'), 'post_unavailable', 'ui.comments.errors.post_not_public'],
    'a reply to a reply' => [function () {
        $post = Post::factory()->published()->create();
        $comment = Comment::factory()->for($post)->create();
        $reply = Comment::factory()->for($post)->create(['parent_id' => $comment->id]);
        app(AddCommentAction::class)->handle(User::factory()->create(), $post, 'Hi.', $reply);
    }, 'reply_target_unavailable', 'ui.comments.errors.reply_target_unavailable'],
    'a banned member' => [fn () => app(AddCommentAction::class)->handle(User::factory()->banned()->create(), Post::factory()->published()->create(), 'Hi.'), 'not_allowed', 'ui.comments.errors.not_allowed'],
    'a guest' => [fn () => app(AddCommentAction::class)->handle(null, Post::factory()->published()->create(), 'Hi.'), 'guest', 'ui.comments.errors.guest'],
    'the commenting limit' => [function () {
        config(['rate_limits.comment.max_attempts' => 1, 'rate_limits.comment.decay_seconds' => 60]);
        $user = User::factory()->create();
        $post = Post::factory()->published()->create();
        app(AddCommentAction::class)->handle($user, $post, 'One.');
        app(AddCommentAction::class)->handle($user, $post, 'Two.');
    }, 'rate_limited', 'ui.rate_limit.commenting'],
]);

it('says how soon the commenting limit allows the next comment', function () {
    config(['rate_limits.comment.max_attempts' => 1, 'rate_limits.comment.decay_seconds' => 60]);
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    app(AddCommentAction::class)->handle($user, $post, 'One.');

    try {
        app(AddCommentAction::class)->handle($user, $post, 'Two.');
        $this->fail('The commenting limit was not reached.');
    } catch (CannotCommentException $refusal) {
        expect($refusal->retryAfterSeconds())->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
            ->and(Comment::query()->count())->toBe(1);
    }
});
