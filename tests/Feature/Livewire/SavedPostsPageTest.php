<?php

use App\Livewire\SavedPosts\SavedPostsPage;
use App\Models\Post;
use App\Models\PostSave;
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
