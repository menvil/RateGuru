<?php

use App\Actions\Comments\AddCommentAction;
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
