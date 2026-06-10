<?php

use App\Actions\Posts\SavePostAction;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;

it('saves published post for user', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);

    $this->assertDatabaseHas('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('is idempotent when saving same post twice', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);
    app(SavePostAction::class)->handle($user, $post);

    expect(PostSave::where('user_id', $user->id)->where('post_id', $post->id)->count())->toBe(1);
});

it('throws when saving unpublished post', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    app(SavePostAction::class)->handle($user, $post);
})->throws(CannotSavePostException::class);

it('throws when feature flag is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => false]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(SavePostAction::class)->handle($user, $post);
})->throws(SavedPostsDisabledException::class);
