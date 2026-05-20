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

it('renders approve button for pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="moderation-approve"', false)
        ->assertSee('Approve')
        ->assertSee('wire:click="approve"', false);
});

it('does not render approve button for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="moderation-approve"', false)
        ->assertDontSee('Approve');
});

it('renders hide button for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="moderation-hide"', false)
        ->assertSee('Hide');
});

it('does not render hide button for pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="moderation-hide"', false);
});

it('renders reject button for pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="moderation-reject"', false)
        ->assertSee('Reject');
});

it('does not render reject button for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="moderation-reject"', false);
});

it('renders restore button for hidden post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="moderation-restore"', false)
        ->assertSee('Restore');
});

it('does not render restore button for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="moderation-restore"', false);
});

it('renders hide confirmation modal markup for published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="hide-confirmation-modal"', false)
        ->assertSee('confirmHideOpen', false)
        ->assertSee('Hide this post?')
        ->assertSee('data-testid="hide-confirmation-cancel"', false)
        ->assertSee('data-testid="hide-confirmation-confirm"', false);
});
