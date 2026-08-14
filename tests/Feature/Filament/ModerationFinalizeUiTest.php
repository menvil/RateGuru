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
    Livewire::test(ListPosts::class)
        ->assertTableActionVisible('restore', $post)
        ->assertTableActionVisible('finalizeRemoval', $post);

    $this->actingAs(User::factory()->moderator()->create());
    Livewire::test(ListPosts::class)
        ->assertTableActionVisible('restore', $post)
        ->assertTableActionHidden('finalizeRemoval', $post);
});

it('finalizes a hidden post through the table action with a required reason', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->callTableAction('finalizeRemoval', $post, data: ['reason' => 'Internal decision.']);

    expect($post->fresh()->isModerationRemovalFinalized())->toBeTrue();

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::FinalizePostRemoval->value,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);
});

it('requires a reason through the finalize table actions', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->callTableAction('finalizeRemoval', $post, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    Livewire::test(ListComments::class)
        ->callTableAction('finalizeRemoval', $comment, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($post->fresh()->moderation_removed_at)->toBeNull()
        ->and($comment->fresh()->moderation_removed_at)->toBeNull();
});

it('hides restore and finalize on a finalized post and shows the derived badge', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();
    app(FinalizePostRemovalAction::class)->handle($admin, $post, 'reason');

    $this->actingAs($admin);

    Livewire::test(ListPosts::class)
        ->assertTableActionHidden('restore', $post)
        ->assertTableActionHidden('finalizeRemoval', $post)
        ->assertSee('Removal finalized');
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
