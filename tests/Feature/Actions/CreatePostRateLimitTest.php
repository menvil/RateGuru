<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Models\Post;
use App\Models\User;

it('blocks excessive post uploads from same user', function () {
    config()->set('rate_limits.upload.max_attempts', 2);
    config()->set('rate_limits.upload.decay_seconds', 600);

    $user = User::factory()->create();

    app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'First post'));
    app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'Second post'));

    app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'Third post'));
})->throws(RateLimitExceededException::class, 'You are uploading too quickly. Please try again later.');

it('does not create post when upload rate limit is exceeded', function () {
    config()->set('rate_limits.upload.max_attempts', 1);
    config()->set('rate_limits.upload.decay_seconds', 600);

    $user = User::factory()->create();

    app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'First post'));

    try {
        app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'Second post'));
    } catch (RateLimitExceededException) {
        // Expected.
    }

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not block another user when one user hits upload limit', function () {
    config()->set('rate_limits.upload.max_attempts', 1);
    config()->set('rate_limits.upload.decay_seconds', 600);

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    app(CreatePostAction::class)->handle($firstUser, new CreatePostData(title: 'First post'));

    try {
        app(CreatePostAction::class)->handle($firstUser, new CreatePostData(title: 'Blocked post'));
    } catch (RateLimitExceededException) {
        // Expected.
    }

    $post = app(CreatePostAction::class)->handle($secondUser, new CreatePostData(title: 'Allowed post'));

    expect($post->user_id)->toBe($secondUser->id);
});
