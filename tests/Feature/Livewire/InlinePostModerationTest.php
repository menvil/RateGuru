<?php

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Livewire\Feed\PostFeed;
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

it('renders moderation reason input and updates reason for moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('name="moderation_reason"', false)
        ->assertSee('maxlength="1000"', false)
        ->set('reason', 'Image violates rules.')
        ->assertSet('reason', 'Image violates rules.');
});

it('does not render moderation reason input for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($user)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('name="moderation_reason"', false);
});

it('approves pending post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Valid post.')
        ->call('approve')
        ->assertDispatched('post-moderated')
        ->assertSet('error', null)
        ->assertSet('success', 'Post approved.');

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::ApprovePost->value,
        'target_type' => Post::class,
        'target_id' => $post->id,
        'reason' => 'Valid post.',
    ]);
});

it('does not approve pending post when invoked by normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($user)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->call('approve')
        ->assertNotDispatched('post-moderated');

    expect($post->fresh()->status)->toBe(PostStatus::Pending);
    $this->assertDatabaseCount('moderation_logs', 0);
});

it('hides published post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Reported content.')
        ->call('hide')
        ->assertDispatched('post-moderated')
        ->assertSet('success', 'Post hidden.');

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::HidePost->value,
        'target_id' => $post->id,
        'reason' => 'Reported content.',
    ]);
});

it('shows error when hiding non published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->call('hide')
        ->assertNotDispatched('post-moderated')
        ->assertSet('error', 'Post status is invalid for this moderation action.');

    expect($post->fresh()->status)->toBe(PostStatus::Pending);
});

it('rejects pending post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Invalid image.')
        ->call('reject')
        ->assertDispatched('post-moderated')
        ->assertSet('success', 'Post rejected.');

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::RejectPost->value,
        'target_id' => $post->id,
    ]);
});

it('restores hidden post from inline moderation', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->set('reason', 'Restored after review.')
        ->call('restore')
        ->assertDispatched('post-moderated')
        ->assertSet('success', 'Post restored.');

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::RestorePost->value,
        'target_id' => $post->id,
    ]);
});

it('dispatches post moderated event with post id and action on hide', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->call('hide')
        ->assertDispatched('post-moderated', postId: $post->id, action: 'hidden');
});

it('refreshes feed when post-moderated event is dispatched', function () {
    $moderator = User::factory()->moderator()->create();
    $visible = Post::factory()->published()->create(['title' => 'Visible post']);

    // Single PostFeed instance: it must see the post on first render,
    // then drop it after handling post-moderated. If the #[On('post-moderated')]
    // listener were missing, the same instance would keep its cached render
    // and still show the title — making this test fail.
    $component = Livewire::actingAs($moderator)
        ->test(PostFeed::class)
        ->assertSee('Visible post');

    Post::query()->whereKey($visible->id)->update(['status' => PostStatus::Hidden]);

    $component
        ->dispatch('post-moderated', postId: $visible->id, action: 'hidden')
        ->assertDontSee('Visible post');
});

it('renders open in admin link placeholder for moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertSee('data-testid="open-in-admin-link"', false)
        ->assertSee('Open in admin');
});

it('does not render open in admin link for normal user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="open-in-admin-link"', false)
        ->assertDontSee('Open in admin');
});

it('does not render broken admin link when filament route is missing', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($moderator)
        ->test(InlinePostModeration::class, ['postId' => $post->id])
        ->assertDontSee('href="http', false);
});
