<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('allows user to delete own comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['comments_count' => 1]);

    $comment = Comment::factory()
        ->for($user)
        ->for($post)
        ->create(['status' => CommentStatus::Visible]);

    app(DeleteCommentAction::class)->handle($user, $comment);

    $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    expect($post->fresh()->comments_count)->toBe(0);
});

it('does not allow user to delete another users comment', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $comment = Comment::factory()->for($owner)->create();

    try {
        app(DeleteCommentAction::class)->handle($otherUser, $comment);
        $this->fail('Expected CannotDeleteCommentException was not thrown.');
    } catch (CannotDeleteCommentException $e) {
        $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
    }
});
