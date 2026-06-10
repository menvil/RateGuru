<?php

use App\Actions\Posts\UnsavePostAction;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;

it('unsaves post for user', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    app(UnsavePostAction::class)->handle($user, $post);

    $this->assertDatabaseMissing('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('is idempotent when unsaving a post that is not saved', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(UnsavePostAction::class)->handle($user, $post);

    expect(PostSave::count())->toBe(0);
});

it('throws when feature flag is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => false]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(UnsavePostAction::class)->handle($user, $post);
})->throws(SavedPostsDisabledException::class);

it('cannot unsave another users saved post', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $owner->id,
        'post_id' => $post->id,
    ]);

    app(UnsavePostAction::class)->handle($other, $post);

    $this->assertDatabaseHas('post_saves', [
        'user_id' => $owner->id,
        'post_id' => $post->id,
    ]);
});
