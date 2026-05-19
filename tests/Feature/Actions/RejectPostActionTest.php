<?php

use App\Actions\Moderation\RejectPostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

it('allows moderator to reject pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    app(RejectPostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Image does not match rules.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);
});

it('allows admin to reject pending post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create();

    app(RejectPostAction::class)->handle($admin, $post);

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);
});

it('does not allow normal user to reject pending post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    app(RejectPostAction::class)->handle($user, $post);
})->throws(CannotModeratePostException::class);

it('does not reject non pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    app(RejectPostAction::class)->handle($moderator, $post);
})->throws(CannotModeratePostException::class);

it('writes moderation log when rejecting post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    app(RejectPostAction::class)->handle($moderator, $post, 'Bad image.');

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => \App\Enums\ModerationActionType::RejectPost->value,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);
});

it('does not write moderation log when normal user fails to reject', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    try {
        app(RejectPostAction::class)->handle($user, $post);
    } catch (CannotModeratePostException $e) {
    }

    $this->assertDatabaseCount('moderation_logs', 0);
});
