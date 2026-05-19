<?php

use App\Actions\Moderation\ApprovePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

it('allows moderator to approve pending post', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->pending()->create([
        'published_at' => null,
    ]);

    app(ApprovePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Looks valid.'
    );

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});

it('allows admin to approve pending post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create();

    app(ApprovePostAction::class)->handle($admin, $post);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('does not allow normal user to approve pending post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    app(ApprovePostAction::class)->handle($user, $post);
})->throws(CannotModeratePostException::class);

it('does not approve non pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    app(ApprovePostAction::class)->handle($moderator, $post);
})->throws(CannotModeratePostException::class);
