<?php

use App\Actions\Moderation\HidePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

it('allows moderator to hide published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    app(HidePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Reported content.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});

it('allows admin to hide published post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    app(HidePostAction::class)->handle($admin, $post);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});

it('does not allow normal user to hide published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(HidePostAction::class)->handle($user, $post);
})->throws(CannotModeratePostException::class);

it('does not hide non published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    app(HidePostAction::class)->handle($moderator, $post);
})->throws(CannotModeratePostException::class);
