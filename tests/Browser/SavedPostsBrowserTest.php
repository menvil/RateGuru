<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can open saved posts page in browser', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create([
        'email' => 'saved-browser@example.com',
        'password' => bcrypt('password'),
    ]);

    $post = Post::factory()->published()->create([
        'title' => 'Browser Saved Post Title',
    ]);

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    actingAs($user);

    visit(route('saved-posts.index'))
        ->assertPresent('[data-testid="saved-posts-page"]')
        ->assertSee('Browser Saved Post Title');
});

it('shows empty state on saved posts page when no saved posts', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create([
        'email' => 'saved-empty-browser@example.com',
        'password' => bcrypt('password'),
    ]);

    actingAs($user);

    visit(route('saved-posts.index'))
        ->assertPresent('[data-testid="saved-posts-empty-state"]');
});
