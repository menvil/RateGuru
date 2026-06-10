<?php

use App\Actions\Posts\ToggleSavedPostAction;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;

it('toggles unsaved post to saved', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $result = app(ToggleSavedPostAction::class)->handle($user, $post);

    expect($result->isSaved)->toBeTrue();

    $this->assertDatabaseHas('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('toggles saved post to unsaved', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $result = app(ToggleSavedPostAction::class)->handle($user, $post);

    expect($result->isSaved)->toBeFalse();

    $this->assertDatabaseMissing('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});
