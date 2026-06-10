<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;

it('can open saved posts page in browser', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create([
        'email' => 'saved-browser@example.com',
        'password' => bcrypt('password'),
    ]);

    Post::factory()->published()->create([
        'title' => 'Browser Saved Post Title',
    ]);

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => Post::query()->first()->id,
    ]);

    loginAs($user);

    visit(route('saved-posts.index'))
        ->assertSee('data-testid="saved-posts-page"', false)
        ->assertSee('Browser Saved Post Title');
});

it('shows empty state on saved posts page when no saved posts', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create([
        'email' => 'saved-empty-browser@example.com',
        'password' => bcrypt('password'),
    ]);

    loginAs($user);

    visit(route('saved-posts.index'))
        ->assertSee('data-testid="saved-posts-empty-state"', false);
});
