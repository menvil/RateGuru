<?php

use App\Livewire\SavedPosts\SavedPostsPage;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use Livewire\Livewire;

/*
 * Saved posts are a feature the project switches on; these are about the
 * feature, so it is on.
 */
beforeEach(function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
});

it('shows saved posts page to owner', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Saved Post Title',
    ]);

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('Saved Post Title');
});

it('requires auth for saved posts page', function () {
    $this->get(route('saved-posts.index'))
        ->assertRedirect(route('login'));
});

it('shows empty state when user has no saved posts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('data-testid="saved-posts-empty-state"', false);
});

it('renders saved posts page component', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SavedPostsPage::class)
        ->assertStatus(200)
        ->assertSee('data-testid="saved-posts-page"', false);
});

it('renders posts as full feed post cards', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create(['title' => 'Saved Feed Card Post']);

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('data-testid="post-card"', false)
        ->assertSee('Saved Feed Card Post');
});

it('renders active rating groups on saved post cards', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'confidence', 'label' => 'Confidence']);
    RatingOption::factory()->count(2)->for($group, 'group')->create();
    PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]);

    Livewire::actingAs($user)
        ->test(SavedPostsPage::class)
        ->assertSee('data-testid="post-card-rating-confidence"', false);
});

it('mounts the global sliding overlay so clicking a saved post opens the same panel as the feed', function () {
    ProjectSettings::query()->sole()->update([
        'feature_flags' => ['show_saved_posts' => true, 'post_detail_overlay_mode' => true],
    ]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('data-testid="post-detail-overlay-backdrop-root"', false)
        ->assertSee('data-testid="post-detail-overlay-host"', false);
});

it('is not there while saved posts are switched off', function () {
    ProjectSettings::query()->sole()->update(['feature_flags' => ['show_saved_posts' => false]]);
    app(ProjectSettingsManager::class)->flush();

    $this->actingAs(User::factory()->create())
        ->get(route('saved-posts.index'))
        ->assertNotFound();
});

it('shows the last page there is for a page past the end, not the empty state', function () {
    $user = User::factory()->create();
    foreach (Post::factory()->count(3)->published()->create() as $post) {
        PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]);
    }

    Livewire::withQueryParams(['page' => 5])
        ->actingAs($user)
        ->test(SavedPostsPage::class)
        ->assertDontSee('data-testid="saved-posts-empty-state"', false)
        ->assertViewHas('savedPosts', fn ($paginator): bool => $paginator->count() === 3 && $paginator->currentPage() === 1);
});
