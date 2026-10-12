<?php

use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Actions\Moderation\FinalizePostRemovalAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Enums\ModerationPurgeOutcome;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use App\Services\Moderation\ModerationContentPurgeService;
use Livewire\Livewire;

it('offers restore and finalize to an admin on a reversible hidden post, restore only to a moderator', function () {
    $post = Post::factory()->hidden()->create();

    $this->actingAs(User::factory()->admin()->create());
    expect(listedPostRow(Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class), $post))
        ->toMatchArray(['restore' => true, 'finalize' => 'available']);

    $this->actingAs(User::factory()->moderator()->create());
    expect(listedPostRow(Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class), $post))
        ->toMatchArray(['restore' => true, 'finalize' => null]);

    // A moderator cannot open the confirmation either.
    Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class)
        ->call('askToFinalize', $post->id)
        ->assertSet('confirming', null);
});

it('tells an admin to hide a published post before finalizing it', function () {
    $post = Post::factory()->published()->create();

    $this->actingAs(User::factory()->admin()->create());

    expect(listedPostRow(Livewire::withQueryParams(['status' => 'published'])->test(ListPosts::class), $post)['finalize'])->toBe('hide first');
});

it('finalizes a hidden post after the irreversible confirmation, with its internal reason', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();

    $this->actingAs($admin);

    Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class)
        ->call('askToFinalize', $post->id)
        ->assertSeeHtml('Finalize removal?')
        ->assertSeeHtml('This can’t be undone.')
        ->assertSeeHtml('I understand this post can no longer be restored.')
        ->set('reason', 'Internal decision.')
        ->set('acknowledged', true)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Removal of #'.$post->id.' finalized', action: null);

    expect($post->fresh()->isModerationRemovalFinalized())->toBeTrue();

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::FinalizePostRemoval->value,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);
});

it('requires a reason and the acknowledgement to finalize, and a reason through the comment table action', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    $this->actingAs($admin);

    Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class)
        ->call('askToFinalize', $post->id)
        ->call('confirm')
        ->assertHasErrors(['reason' => 'required', 'acknowledged' => 'accepted'])
        ->set('reason', 'Internal decision.')
        ->call('confirm')
        ->assertHasErrors(['acknowledged' => 'accepted']);

    Livewire::test(ListComments::class)
        ->callTableAction('finalizeRemoval', $comment, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($post->fresh()->moderation_removed_at)->toBeNull()
        ->and($comment->fresh()->moderation_removed_at)->toBeNull();
});

it('leaves nothing to do on a finalized post, and shows the derived badge', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();
    app(FinalizePostRemovalAction::class)->handle($admin, $post, 'reason');

    $this->actingAs($admin);

    $page = Livewire::withQueryParams(['status' => 'hidden'])->test(ListPosts::class);

    expect(listedPostRow($page, $post))->toMatchArray(['restore' => false, 'finalize' => null, 'final' => true])
        ->and(listedPostRow($page, $post)['status']['label'])->toBe('Removal finalized');

    $page->assertSeeHtml('Removal finalized. No further actions.');
});

it('supports finalizing hidden author-deleted comments and hides actions once finalized', function () {
    $admin = User::factory()->admin()->create();

    // Hidden author-deleted row is reachable (withTrashed listing) and
    // finalizable from the table.
    $trashed = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    Comment::query()->whereKey($trashed->id)->update(['deleted_at' => now()]);
    $record = Comment::withTrashed()->findOrFail($trashed->id);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->assertTableActionVisible('finalizeRemoval', $record)
        ->callTableAction('finalizeRemoval', $record, data: ['reason' => 'Internal decision.']);

    expect(Comment::withTrashed()->findOrFail($trashed->id)->isModerationRemovalFinalized())->toBeTrue();

    Livewire::test(ListComments::class)
        ->assertTableActionHidden('restore', $record)
        ->assertTableActionHidden('finalizeRemoval', $record)
        ->assertSee('Removal finalized');
});

it('hides comment finalization from moderators', function () {
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    $this->actingAs(User::factory()->moderator()->create());

    Livewire::test(ListComments::class)
        ->assertTableActionVisible('restore', $comment)
        ->assertTableActionHidden('finalizeRemoval', $comment);
});

it('renders moderation history null-safe after the target is purged', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 0]);

    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    app(FinalizeCommentRemovalAction::class)->handle($admin, $comment, 'reason');

    expect(app(ModerationContentPurgeService::class)->purgeComment($comment->id))
        ->toBe(ModerationPurgeOutcome::Purged);

    // The log survives its target and admin rendering stays intact.
    expect(ModerationLog::query()->where('target_id', $comment->id)->count())->toBeGreaterThanOrEqual(1);

    $this->actingAs($admin);
    Livewire::test(ListComments::class)->assertOk();
});
