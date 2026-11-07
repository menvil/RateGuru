<?php

use App\Livewire\SavedPosts\SavedPostsPage;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Livewire;

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

it('mounts the global sliding overlay so clicking a saved post opens the same panel as the feed', function (bool $overlayMode) {
    // The page has no detail column of its own, so it needs the overlay in either view mode.
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => $overlayMode],
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
})->with(['overlay mode' => true, 'split view' => false]);

it('keys each saved card by its post, on the card itself, so a new page never hands one post\'s card to another', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->count(2)->published()->create();
    $posts->each(fn (Post $post) => PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]));

    $html = Livewire::actingAs($user)->test(SavedPostsPage::class)->html();

    foreach ($posts as $post) {
        expect($html)->toMatch('/<div(?=[^>]*\swire:key="saved-post-'.$post->id.'")(?=[^>]*data-testid="post-card")/');
    }
});
