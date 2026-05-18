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
