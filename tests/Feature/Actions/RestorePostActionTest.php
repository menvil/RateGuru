<?php

use App\Actions\Moderation\RestorePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

it('allows moderator to restore hidden post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    app(RestorePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Reviewed and restored.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('allows admin to restore hidden post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();

    app(RestorePostAction::class)->handle($admin, $post);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('does not allow normal user to restore hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    app(RestorePostAction::class)->handle($user, $post);
})->throws(CannotModeratePostException::class);

it('does not restore non hidden post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->rejected()->create();

    app(RestorePostAction::class)->handle($moderator, $post);
})->throws(CannotModeratePostException::class);

it('writes moderation log when restoring post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    app(RestorePostAction::class)->handle($moderator, $post, 'Reviewed.');

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => \App\Enums\ModerationActionType::RestorePost->value,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);
});

it('does not write moderation log when normal user fails to restore', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    try {
        app(RestorePostAction::class)->handle($user, $post);
    } catch (CannotModeratePostException $e) {
    }

    $this->assertDatabaseCount('moderation_logs', 0);
});
