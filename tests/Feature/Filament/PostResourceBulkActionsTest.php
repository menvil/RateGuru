<?php

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

/**
 * Moderating posts together: the rows selected on a page, the bulk bar that
 * says what each action applies to and what it will skip, Approve selected
 * at once and Hide selected after asking for a reason.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->moderator()->create());
});

it('selects every row on the page, and clears the selection when the list changes', function () {
    $posts = Post::factory()->count(3)->pending()->create();

    Livewire::test(ListPosts::class)
        ->call('togglePage')
        ->assertSet('selected', $posts->sortBy('id')->pluck('id')->map(fn (int $id): string => (string) $id)->values()->all())
        ->assertSeeHtml('3 selected')
        ->assertSeeHtml('Approve selected (3)')
        ->call('togglePage')
        ->assertSet('selected', [])
        ->set('selected', [(string) $posts[0]->id])
        ->set('status', 'all')
        ->assertSet('selected', []);
});

it('says how many selected posts each action applies to, and how many it skips', function () {
    $pending = Post::factory()->pending()->create();
    $published = Post::factory()->published()->create();
    $hidden = Post::factory()->hidden()->create();

    Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class)
        ->set('selected', [(string) $pending->id, (string) $published->id, (string) $hidden->id])
        ->assertViewHas('selection', ['count' => 3, 'pending' => 1, 'published' => 1, 'skipped' => 1])
        ->assertSeeHtml('Approve selected (1)')
        ->assertSeeHtml('Hide selected (1)')
        ->assertSeeHtml('1 selected post is neither pending nor published and will be skipped');
});

it('approves the selected pending posts at once, leaving the rest as they are', function () {
    $pending = Post::factory()->count(2)->pending()->create();
    $published = Post::factory()->published()->create();

    Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class)
        ->set('selected', [...$pending->pluck('id')->map(fn (int $id): string => (string) $id)->all(), (string) $published->id])
        ->call('approveSelected')
        ->assertSet('selected', [])
        ->assertDispatched('rg-admin-toast', message: '2 posts approved and published');

    expect($pending->map(fn (Post $post): PostStatus => $post->fresh()->status)->unique()->all())->toBe([PostStatus::Published])
        ->and($published->fresh()->status)->toBe(PostStatus::Published);
});

it('hides the selected published posts after asking for a reason, and Undo restores them all', function () {
    $published = Post::factory()->count(2)->published()->create();
    $pending = Post::factory()->pending()->create();
    $ids = $published->pluck('id')->sort()->values()->all();

    $page = Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class)
        ->set('selected', [...array_map(strval(...), $ids), (string) $pending->id])
        ->call('askToHideSelected')
        ->assertSet('confirming', ['action' => 'hide', 'ids' => $ids])
        ->assertSeeHtml('Hide 2 posts?')
        ->set('reason', 'Spam')
        ->call('confirm')
        ->assertSet('selected', [])
        ->assertDispatched('rg-admin-toast', message: '2 posts hidden', action: ['label' => 'Undo', 'event' => 'restore-posts', 'params' => ['ids' => $ids]]);

    expect($published->map(fn (Post $post): PostStatus => $post->fresh()->status)->unique()->all())->toBe([PostStatus::Hidden])
        ->and($pending->fresh()->status)->toBe(PostStatus::Pending);

    $page->dispatch('restore-posts', ids: $ids)->assertDispatched('rg-admin-toast', message: '2 posts are visible again');

    expect($published->map(fn (Post $post): PostStatus => $post->fresh()->status)->unique()->all())->toBe([PostStatus::Published]);
});

it('asks nothing when none of the selected posts can be hidden', function () {
    $pending = Post::factory()->pending()->create();

    Livewire::test(ListPosts::class)
        ->set('selected', [(string) $pending->id])
        ->call('askToHideSelected')
        ->assertSet('confirming', null);
});
