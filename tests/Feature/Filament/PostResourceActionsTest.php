<?php

use App\Actions\Moderation\HidePostAction;
use App\Actions\Posts\DeletePostAction;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

/**
 * Moderating one post from its row: Approve at once, Reject with an optional
 * reason, Hide with a required one and Restore at once, each through its
 * moderation action, which logs it — Hide and Restore with Undo. What a post
 * offers depends on its status; a post its author deleted offers nothing,
 * and nothing deletes a post from here.
 */
beforeEach(function () {
    $this->moderator = User::factory()->moderator()->create();
    $this->actingAs($this->moderator);
});

it('offers each status its own actions, and nothing to a post its author deleted', function () {
    $pending = Post::factory()->pending()->create();
    $published = Post::factory()->published()->create();
    $hidden = Post::factory()->hidden()->create();
    $owner = User::factory()->create();
    $deleted = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $deleted);

    $page = Livewire::withQueryParams(['status' => 'all'])->test(ListPosts::class);
    $actions = fn (Post $post): array => array_keys(array_filter(array_intersect_key(listedRow($page, $post), array_flip(['approve', 'reject', 'hide', 'restore'])), fn (bool $offered): bool => $offered));

    expect($actions($pending))->toBe(['approve', 'reject'])
        ->and($actions($published))->toBe(['hide'])
        ->and($actions($hidden))->toBe(['restore'])
        ->and($actions($deleted))->toBe([]);

    expect($page->html())->not->toContain('wire:click="delete');
});

it('approves a pending post at once, without asking', function () {
    $post = Post::factory()->pending()->create(['title' => 'Grandma’s dumplings']);

    Livewire::test(ListPosts::class)
        ->call('approve', $post->id)
        ->assertSet('confirming', null)
        ->assertDispatched('rg-admin-toast', message: '“Grandma’s dumplings” approved and published', tone: 'success', action: null);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('rejects a pending post after asking, with the reason when one is given', function (string $reason, ?string $logged) {
    $post = Post::factory()->pending()->create(['title' => 'Buy vitamins']);

    Livewire::test(ListPosts::class)
        ->call('askToReject', $post->id)
        ->assertSet('confirming', ['action' => 'reject', 'ids' => [$post->id]])
        ->assertSeeHtml('Reject this post?')
        ->assertSeeHtml('Spam')
        ->set('reason', $reason)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('confirming', null)
        ->assertDispatched('rg-admin-toast', message: '“Buy vitamins” rejected');

    expect($post->fresh()->status)->toBe(PostStatus::Rejected)
        ->and(ModerationLog::query()->where('action', ModerationActionType::RejectPost)->sole()->reason)->toBe($logged);
})->with([
    'with a reason' => ['Spam', 'Spam'],
    'without one' => ['', null],
]);

it('hides a published post only with a reason, and Undo restores it', function () {
    $post = Post::factory()->published()->create(['title' => 'Free kittens']);

    $page = Livewire::withQueryParams(['status' => 'published'])->test(ListPosts::class)
        ->call('askToHide', $post->id)
        ->assertSeeHtml('Hide this post?')
        ->call('confirm')
        ->assertHasErrors(['reason' => 'required']);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $page->set('reason', 'Animal sale')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: '“Free kittens” hidden', action: ['label' => 'Undo', 'event' => 'restore-posts', 'params' => ['ids' => [$post->id]]]);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden)
        ->and(ModerationLog::query()->where('action', ModerationActionType::HidePost)->sole()->reason)->toBe('Animal sale');

    $page->dispatch('restore-posts', ids: [$post->id])
        ->assertDispatched('rg-admin-toast', message: '“Free kittens” is visible again');

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('restores a hidden post at once, and Undo hides it again', function () {
    $post = Post::factory()->hidden()->create(['title' => 'Cat fight compilation']);

    $page = Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class)
        ->call('restore', $post->id)
        ->assertDispatched('rg-admin-toast', message: '“Cat fight compilation” restored and visible again', action: ['label' => 'Undo', 'event' => 'hide-posts-again', 'params' => ['ids' => [$post->id]]]);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $page->dispatch('hide-posts-again', ids: [$post->id]);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});

it('holds a reason to its limit', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ListPosts::class)
        ->call('askToHide', $post->id)
        ->set('reason', str_repeat('r', ListPosts::REASON_LIMIT + 1))
        ->call('confirm')
        ->assertHasErrors(['reason' => 'max'])
        ->set('reason', 'ok')
        ->call('confirm')
        ->assertHasErrors(['reason' => 'min']);
});

it('says so, and does nothing, when the post changed meanwhile', function () {
    $post = Post::factory()->published()->create();
    $page = Livewire::withQueryParams(['status' => 'published'])->test(ListPosts::class);
    app(HidePostAction::class)->handle(User::factory()->moderator()->create(), $post, 'Elsewhere');

    $page->call('approve', $post->id)
        ->assertDispatched('rg-admin-toast', message: 'This post changed meanwhile, so nothing was done. The list now shows it as it is.', tone: 'error');

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});

it('never acts on a post the list does not hold', function () {
    $legacy = Post::factory()->pending()->create();
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()]);

    Livewire::test(ListPosts::class)->call('approve', $legacy->id)->assertNotDispatched('rg-admin-toast');

    expect(Post::withTrashed()->findOrFail($legacy->id)->status)->toBe(PostStatus::Pending);
});
