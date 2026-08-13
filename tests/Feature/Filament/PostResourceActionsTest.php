<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('approves a pending post via the approve table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableAction('approve', $post);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $post->id,
        'moderator_id' => $moderator->id,
    ]);
});

it('approve table action does not render a reason form', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->assertTableActionExists(
            'approve',
            function ($action): bool {
                $schema = new ReflectionProperty($action, 'schema');
                $schema->setAccessible(true);

                return $schema->getValue($action) === null;
            },
            $post,
        );
});

it('hides the approve action for non-pending posts', function () {
    $moderator = User::factory()->moderator()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('approve', $published);
});

it('rejects a pending post via the reject table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableAction('reject', $post, data: ['reason' => 'Invalid content.']);

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);

    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $post->id,
        'moderator_id' => $moderator->id,
    ]);
});

it('hides the reject action for non-pending posts', function () {
    $moderator = User::factory()->moderator()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('reject', $published);
});

it('hides a published post via the hide table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableAction('hide', $post, data: ['reason' => 'Reported content.']);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);

    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $post->id,
        'moderator_id' => $moderator->id,
    ]);
});

it('hides the hide action for non-published posts', function () {
    $moderator = User::factory()->moderator()->create();
    $pending = Post::factory()->pending()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('hide', $pending);
});

it('restores a hidden post via the restore table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->callTableAction('restore', $post, data: ['reason' => 'Reviewed and restored.']);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'target_type' => Post::class,
        'target_id' => $post->id,
        'moderator_id' => $moderator->id,
    ]);
});

it('hides the restore action for non-hidden posts', function () {
    $moderator = User::factory()->moderator()->create();
    $published = Post::factory()->published()->create();

    $this->actingAs($moderator);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('restore', $published);
});

it('exposes no generic admin delete table action at all', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertTableActionDoesNotExist('delete');

    $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
});

it('shows author-deleted posts labeled and with no moderation actions', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    app(DeletePostAction::class)->handle($owner, $post);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->filterTable('author_deleted')
        ->assertCanSeeTableRecords([$post])
        ->assertTableActionHidden('approve', $post)
        ->assertTableActionHidden('reject', $post)
        ->assertTableActionHidden('hide', $post)
        ->assertTableActionHidden('restore', $post);
});

it('never offers moderation restore as author restore on deleted rows', function () {
    // The moderation restore action targets Hidden posts only; an
    // author-deleted row must not resurrect through the admin table.
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    app(DeletePostAction::class)->handle($owner, $post);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('restore', $post);

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
});
