<?php

use App\Actions\Comments\AddCommentAction;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

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
