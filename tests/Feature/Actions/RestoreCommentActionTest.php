<?php

use App\Actions\Comments\RestoreCommentAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('allows moderator to restore a hidden comment', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create(['comments_count' => 0]);
    $comment = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Hidden,
    ]);

    app(RestoreCommentAction::class)->handle($moderator, $comment, 'Restored after review.');

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
    expect($post->fresh()->comments_count)->toBe(1);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::RestoreComment->value,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'reason' => 'Restored after review.',
    ]);
});

it('allows admin to restore a hidden comment', function () {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Hidden]);

    app(RestoreCommentAction::class)->handle($admin, $comment);

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
});

it('does not allow normal user to restore a comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Hidden]);

    try {
        app(RestoreCommentAction::class)->handle($user, $comment);
        $this->fail('Expected CannotRestoreCommentException was not thrown.');
    } catch (CannotRestoreCommentException $e) {
        expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
    }
});

it('does not allow restoring an already visible comment', function () {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

    try {
        app(RestoreCommentAction::class)->handle($moderator, $comment);
        $this->fail('Expected CannotRestoreCommentException was not thrown.');
    } catch (CannotRestoreCommentException $e) {
        expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
    }
});
