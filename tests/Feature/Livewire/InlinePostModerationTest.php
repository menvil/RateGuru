<?php

use App\Livewire\Moderation\InlinePostModeration;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('can render inline post moderation component', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertStatus(200);
});

it('hides inline post moderation for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($user)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="inline-post-moderation-panel"', false)
        ->assertDontSee('Approve')
        ->assertDontSee('Reject')
        ->assertDontSee('Hide')
        ->assertDontSee('Restore');
});

it('hides inline post moderation for guest', function () {
    $post = Post::factory()->pending()->create();

    Livewire::test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="inline-post-moderation-panel"', false)
        ->assertDontSee('Approve');
});

it('shows inline post moderation panel for moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="inline-post-moderation-panel"', false)
        ->assertSee('Moderator');
});

it('shows inline post moderation panel for admin', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($admin)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="inline-post-moderation-panel"', false);
});
