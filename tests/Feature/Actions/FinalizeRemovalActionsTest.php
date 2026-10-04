<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Actions\Moderation\FinalizePostRemovalAction;
use App\Actions\Moderation\RestorePostAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Exceptions\Moderation\CannotFinalizeRemovalException;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\MediaAsset;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\Report;
use App\Models\User;

// ------------------------------------------------------------- post finalize

it('finalizes a hidden post: only moderation_removed_at, review normalization and one log', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->withImage()->create(['needs_review' => true]);

    Comment::factory()->create(['post_id' => $post->id]);
    PostVote::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);
    Report::factory()->create(['target_type' => Post::class, 'target_id' => $post->id]);

    app(FinalizePostRemovalAction::class)->handle($admin, $post, 'Illegal content.');

    $fresh = $post->fresh();
    expect($fresh->status)->toBe(PostStatus::Hidden)
        ->and($fresh->deleted_at)->toBeNull()
        ->and($fresh->moderation_removed_at)->not->toBeNull()
        ->and($fresh->needs_review)->toBeFalse()
        ->and($fresh->isModerationRemovalFinalized())->toBeTrue();

    // Nothing physically deleted: graph, reports and media all remain.
    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(PostVote::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(Report::query()->count())->toBe(1)
        ->and(MediaAsset::withTrashed()->findOrFail($fresh->image_asset_id)->trashed())->toBeFalse();

    $log = ModerationLog::query()->where('action', ModerationActionType::FinalizePostRemoval)->get();
    expect($log)->toHaveCount(1)
        ->and($log->first()->reason)->toBe('Illegal content.')
        ->and($log->first()->metadata['from_state'])->toBe('hidden')
        ->and($log->first()->metadata['to_state'])->toBe('removal_finalized');
});

it('requires a non-empty internal reason to finalize', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();

    expect(fn () => app(FinalizePostRemovalAction::class)->handle($admin, $post, '   '))
        ->toThrow(CannotFinalizeRemovalException::class, 'requires an internal reason');

    expect($post->fresh()->moderation_removed_at)->toBeNull()
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects post finalization from non-admin or sanctioned actors', function (callable $actorFactory) {
    $actor = $actorFactory();
    $post = Post::factory()->hidden()->create();

    expect(fn () => app(FinalizePostRemovalAction::class)->handle($actor, $post, 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    expect($post->fresh()->moderation_removed_at)->toBeNull()
        ->and(ModerationLog::query()->count())->toBe(0);
})->with([
    'moderator' => [fn () => User::factory()->moderator()->create()],
    'ordinary user' => [fn () => User::factory()->create()],
    'limited admin' => [fn () => User::factory()->admin()->limited()->create()],
    'banned admin' => [fn () => User::factory()->admin()->banned()->create()],
]);

it('rejects a stale admin finalizing after being sanctioned', function () {
    $staleAdmin = User::factory()->admin()->create();
    $post = Post::factory()->hidden()->create();

    User::query()->whereKey($staleAdmin->id)->update(['status' => UserStatus::Banned]);
    expect($staleAdmin->status)->toBe(UserStatus::Active);

    expect(fn () => app(FinalizePostRemovalAction::class)->handle($staleAdmin, $post, 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    expect($post->fresh()->moderation_removed_at)->toBeNull();
});

it('rejects post finalization for every non-finalizable target state', function (callable $postFactory) {
    $admin = User::factory()->admin()->create();
    $post = $postFactory();

    expect(fn () => app(FinalizePostRemovalAction::class)->handle($admin, Post::withTrashed()->findOrFail($post->id), 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    expect(ModerationLog::query()->where('action', ModerationActionType::FinalizePostRemoval)->count())->toBe(0);
})->with([
    'published' => [fn () => Post::factory()->published()->create()],
    'draft' => [fn () => Post::factory()->draft()->create()],
    'pending' => [fn () => Post::factory()->pending()->create()],
    'rejected' => [fn () => Post::factory()->rejected()->create()],
    'author-deleted' => [fn () => Post::factory()->authorDeleted()->create()],
    'already finalized' => [fn () => Post::factory()->hidden()->create(['moderation_removed_at' => now()])],
    'malformed soft-deleted hidden' => [function () {
        $post = Post::factory()->hidden()->create();
        Post::query()->whereKey($post->id)->update(['deleted_at' => now()]);

        return $post;
    }],
]);

it('rejects finalization through a stale target that was restored first', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $stalePost = Post::factory()->hidden()->create();

    app(RestorePostAction::class)->handle($moderator, Post::findOrFail($stalePost->id));

    expect($stalePost->status)->toBe(PostStatus::Hidden);

    expect(fn () => app(FinalizePostRemovalAction::class)->handle($admin, $stalePost, 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    expect($stalePost->fresh()->status)->toBe(PostStatus::Published)
        ->and($stalePost->fresh()->moderation_removed_at)->toBeNull();
});

// ---------------------------------------------------------- restore guards

it('restores a hidden non-finalized post but rejects a finalized one, even stale', function () {
    $moderator = User::factory()->moderator()->create();
    $admin = User::factory()->admin()->create();

    $reversible = Post::factory()->hidden()->create();
    app(RestorePostAction::class)->handle($moderator, $reversible);
    expect($reversible->fresh()->status)->toBe(PostStatus::Published);

    $stale = Post::factory()->hidden()->create();
    app(FinalizePostRemovalAction::class)->handle($admin, Post::findOrFail($stale->id), 'reason');

    expect(fn () => app(RestorePostAction::class)->handle($moderator, $stale))
        ->toThrow(CannotModeratePostException::class);

    $fresh = $stale->fresh();
    expect($fresh->status)->toBe(PostStatus::Hidden)
        ->and($fresh->moderation_removed_at)->not->toBeNull();
});

// ---------------------------------------------------------- comment finalize

it('finalizes a hidden live comment and a hidden author-deleted comment', function () {
    $admin = User::factory()->admin()->create();

    $live = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    app(FinalizeCommentRemovalAction::class)->handle($admin, $live, 'Evidence retained.');

    expect($live->fresh()->isModerationRemovalFinalized())->toBeTrue()
        ->and($live->fresh()->trashed())->toBeFalse();

    // PR-D shape: Hide -> author Delete; the trashed row is still evidence.
    $trashed = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    Comment::query()->whereKey($trashed->id)->update(['deleted_at' => now()]);

    app(FinalizeCommentRemovalAction::class)->handle($admin, Comment::withTrashed()->findOrFail($trashed->id), 'Evidence retained.');

    $freshTrashed = Comment::withTrashed()->findOrFail($trashed->id);
    expect($freshTrashed->isModerationRemovalFinalized())->toBeTrue()
        ->and($freshTrashed->trashed())->toBeTrue();

    expect(ModerationLog::query()->where('action', ModerationActionType::FinalizeCommentRemoval)->count())->toBe(2);
});

it('rejects comment finalization for visible, already finalized or unauthorized shapes', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();

    $visible = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Visible]);
    expect(fn () => app(FinalizeCommentRemovalAction::class)->handle($admin, $visible, 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    $finalized = Comment::factory()->for(Post::factory()->published(), 'post')->create([
        'status' => CommentStatus::Hidden,
        'moderation_removed_at' => now(),
    ]);
    expect(fn () => app(FinalizeCommentRemovalAction::class)->handle($admin, $finalized, 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    $hidden = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    expect(fn () => app(FinalizeCommentRemovalAction::class)->handle($moderator, $hidden, 'reason'))
        ->toThrow(CannotFinalizeRemovalException::class);

    // Invalid or unauthorized finalizations log nothing.
    expect(ModerationLog::query()->count())->toBe(0);
});

it('restores a hidden non-finalized comment but rejects a finalized one', function () {
    $moderator = User::factory()->moderator()->create();
    $admin = User::factory()->admin()->create();

    $reversible = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    app(RestoreCommentAction::class)->handle($moderator, $reversible);
    expect($reversible->fresh()->status)->toBe(CommentStatus::Visible);

    $finalized = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    app(FinalizeCommentRemovalAction::class)->handle($admin, Comment::findOrFail($finalized->id), 'reason');

    expect(fn () => app(RestoreCommentAction::class)->handle($moderator, $finalized))
        ->toThrow(CannotRestoreCommentException::class);

    expect($finalized->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('lets the author delete a hidden non-finalized comment but rejects a finalized one', function () {
    $author = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $admin = User::factory()->admin()->create();

    // PR-D preserved: Hide -> author delete still works while reversible.
    $hidden = Comment::factory()->for($author)->for(Post::factory()->published(), 'post')->create();
    app(HideCommentAction::class)->handle($moderator, $hidden);
    app(DeleteCommentAction::class)->handle($author, $hidden);
    expect(Comment::withTrashed()->findOrFail($hidden->id)->trashed())->toBeTrue();

    // Finalized crosses into moderation retention: author delete rejects.
    $finalized = Comment::factory()->for($author)->for(Post::factory()->published(), 'post')->create();
    app(HideCommentAction::class)->handle($moderator, $finalized);
    app(FinalizeCommentRemovalAction::class)->handle($admin, Comment::findOrFail($finalized->id), 'reason');

    expect(fn () => app(DeleteCommentAction::class)->handle($author, $finalized))
        ->toThrow(CannotDeleteCommentException::class);

    expect(Comment::withTrashed()->findOrFail($finalized->id)->trashed())->toBeFalse();
});

it('keeps comment votes and reports when finalizing a comment', function () {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    CommentVote::create(['comment_id' => $comment->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);
    Report::factory()->create(['target_type' => Comment::class, 'target_id' => $comment->id]);

    app(FinalizeCommentRemovalAction::class)->handle($admin, $comment, 'reason');

    expect(CommentVote::query()->count())->toBe(1)
        ->and(Report::query()->count())->toBe(1);
});
