<?php

use App\Livewire\Posts\SavePostButton;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('toggles a saved post for authenticated users', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(SavePostButton::class, ['postId' => $post->id])
        ->assertSee('Save')
        ->call('toggle')
        ->assertSet('saved', true)
        ->assertDontSee('data-testid="save-post-message"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('fill-current', false);

    $this->assertDatabaseHas('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    Livewire::actingAs($user)
        ->test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertSet('saved', false)
        ->assertDontSee('Removed');

    $this->assertDatabaseMissing('post_saves', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('asks guests to log in before saving', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(SavePostButton::class, ['postId' => $post->id])
        ->call('toggle')
        ->assertSet('saved', false)
        ->assertSee('Log in to save posts.');

    $this->assertDatabaseMissing('post_saves', [
        'post_id' => $post->id,
    ]);
});

it('derives the visible status message in the component', function () {
    $post = Post::factory()->published()->create();
    $component = Livewire::test(SavePostButton::class, ['postId' => $post->id]);

    $component->set('message', 'Saved');
    expect($component->get('displayMessage'))->toBeNull();

    $component->set('message', 'Removed');
    expect($component->get('displayMessage'))->toBeNull();

    $component
        ->set('message', 'This post is unavailable.')
        ->assertSee('data-testid="save-post-message"', false)
        ->assertSee('This post is unavailable.');

    expect($component->get('displayMessage'))->toBe('This post is unavailable.');
});
