<?php

use App\Actions\Comments\AddCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('blocks excessive comments from same user', function () {
    config()->set('rate_limits.comment.max_attempts', 2);
    config()->set('rate_limits.comment.decay_seconds', 60);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle($user, $post, 'First');
    app(AddCommentAction::class)->handle($user, $post->fresh(), 'Second');

    app(AddCommentAction::class)->handle($user, $post->fresh(), 'Third');
})->throws(CannotCommentException::class, 'You are commenting too quickly. Please try again later.');

it('does not create comment or increment count when comment rate limit is exceeded', function () {
    config()->set('rate_limits.comment.max_attempts', 1);
    config()->set('rate_limits.comment.decay_seconds', 60);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['comments_count' => 0]);

    app(AddCommentAction::class)->handle($user, $post, 'First');

    try {
        app(AddCommentAction::class)->handle($user, $post->fresh(), 'Second');
    } catch (CannotCommentException) {
        // Expected.
    }

    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(1);
    expect($post->fresh()->comments_count)->toBe(1);
});

it('does not block another user when first user hits comment limit', function () {
    config()->set('rate_limits.comment.max_attempts', 1);
    config()->set('rate_limits.comment.decay_seconds', 60);

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle($firstUser, $post, 'First');

    $thrown = false;

    try {
        app(AddCommentAction::class)->handle($firstUser, $post->fresh(), 'Blocked');
    } catch (CannotCommentException) {
        $thrown = true;
    }

    $this->assertTrue($thrown, 'Expected first user to be rate limited.');

    $comment = app(AddCommentAction::class)->handle($secondUser, $post->fresh(), 'Allowed');

    expect($comment->user_id)->toBe($secondUser->id);
});
