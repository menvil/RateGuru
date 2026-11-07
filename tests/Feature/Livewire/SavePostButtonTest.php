<?php

use App\Livewire\Posts\SavePostButton;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('renders save post button for authenticated user when feature is enabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(SavePostButton::class, ['postId' => $post->id])
        ->assertSee('data-testid="save-post-button"', false);
});

it('toggles a saved post for authenticated users', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertSet('saved', true)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('fill-current', false);

    $this->assertDatabaseHas('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    Livewire::actingAs($user)
        ->test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertSet('saved', false);

    $this->assertDatabaseMissing('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('shows login required message for guests', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $post = Post::factory()->published()->create();

    Livewire::test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertSet('saved', false)
        ->assertSee('Log in to save posts.');

    $this->assertDatabaseMissing('post_saves', [
        'post_id' => $post->id,
    ]);
});

it('shows feature disabled message when feature flag is off', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => false]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertSee('data-testid="save-post-message"', false);
});

it('saves when it shows Save, even after another button for the post saved it meanwhile', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    // The card's button and the open drawer's, both drawn showing Save.
    $card = Livewire::actingAs($user)->test(SavePostButton::class, ['postId' => $post->id]);
    Livewire::actingAs($user)->test(SavePostButton::class, ['postId' => $post->id])->call('toggle')->assertSet('saved', true);

    // The card still shows Save; pressing it means save, not undo the drawer's save.
    $card->call('toggle')->assertSet('saved', true);

    expect($user->postSaves()->where('post_id', $post->id)->exists())->toBeTrue();
});

it('tells the other buttons for the same post what it did, and no others', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)->test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertDispatched('saved-post-changed', postId: $post->id, saved: true);

    Livewire::actingAs($user)->test(SavePostButton::class, ['postId' => $post->id, 'preloadedSaved' => false])
        ->dispatch('saved-post-changed', postId: $post->id + 1, saved: true)
        ->assertSet('saved', false)
        ->dispatch('saved-post-changed', postId: $post->id, saved: true)
        ->assertSet('saved', true);
});
