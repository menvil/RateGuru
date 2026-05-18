<?php

use App\Actions\Comments\HideCommentAction;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('allows moderator to hide comment', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->published()->create(['comments_count' => 1]);

    $comment = Comment::factory()
        ->for($post)
        ->create(['status' => CommentStatus::Visible]);

    app(HideCommentAction::class)->handle($moderator, $comment);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
    expect($post->fresh()->comments_count)->toBe(0);
});

it('does not allow normal user to hide comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

    try {
        app(HideCommentAction::class)->handle($user, $comment);
        $this->fail('Expected CannotHideCommentException was not thrown.');
    } catch (CannotHideCommentException $e) {
        expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
    }
});
