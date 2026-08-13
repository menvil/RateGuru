<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Livewire\Posts\RecentlyDeletedPostsPage;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['posts.author_delete_retention_days' => 30]);
});

it('requires authentication', function () {
    $this->get(route('posts.recently-deleted'))->assertRedirect(route('login'));
});

it('lists only the owners author-deleted posts with deadline info', function () {
    $owner = User::factory()->create();
    $mine = Post::factory()->published()->for($owner)->create(['title' => 'Mine, deleted']);
    app(DeletePostAction::class)->handle($owner, $mine);

    // Noise that must not appear: my live post, a foreign deleted post,
    // and a legacy soft-deleted row without the Deleted status.
    Post::factory()->published()->for($owner)->create(['title' => 'Mine, live']);

    $other = User::factory()->create();
    $foreign = Post::factory()->published()->for($other)->create(['title' => 'Foreign, deleted']);
    app(DeletePostAction::class)->handle($other, $foreign);

    $legacy = Post::factory()->published()->for($owner)->create(['title' => 'Mine, legacy shape']);
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()]);

    $this->actingAs($owner);

    Livewire::test(RecentlyDeletedPostsPage::class)
        ->assertSee('Mine, deleted')
        ->assertDontSee('Mine, live')
        ->assertDontSee('Foreign, deleted')
        ->assertDontSee('Mine, legacy shape')
        ->assertSee('days left');
});

it('restores an own post from the page', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $this->actingAs($owner);

    Livewire::test(RecentlyDeletedPostsPage::class)
        ->call('restore', $post->id)
        ->assertSet('statusMessage', __('ui.recently_deleted.restored', ['title' => $post->title]));

    expect(Post::findOrFail($post->id)->status)->toBe(PostStatus::Published);
});

it('shows expired posts without a restore button and refuses restoring them', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create(['title' => 'Expired one']);
    app(DeletePostAction::class)->handle($owner, $post);

    $this->travel(31)->days();

    $this->actingAs($owner);

    Livewire::test(RecentlyDeletedPostsPage::class)
        ->assertSee('Expired one')
        ->assertSee(__('ui.recently_deleted.expired'))
        ->assertDontSeeHtml('data-testid="restore-post-'.$post->id.'"')
        ->call('restore', $post->id)
        ->assertSet('statusMessage', 'The restore window for this post has expired.');

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
});

it('cannot restore a foreign post even by id', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $intruder = User::factory()->create();
    $this->actingAs($intruder);

    Livewire::test(RecentlyDeletedPostsPage::class)
        ->call('restore', $post->id)
        ->assertSet('statusMessage', __('ui.recently_deleted.unavailable'));

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
});

it('renders no public link to the deleted post', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $this->actingAs($owner);

    Livewire::test(RecentlyDeletedPostsPage::class)
        ->assertDontSeeHtml(route('posts.show', $post->id));
});

it('offers no permanent-delete control', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $this->actingAs($owner);

    Livewire::test(RecentlyDeletedPostsPage::class)
        ->assertDontSee('Delete permanently')
        ->assertDontSeeHtml('wire:click="forceDelete');
});
