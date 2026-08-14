<?php

use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Actions\Moderation\FinalizePostRemovalAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Queries\Comments\CommentListQuery;
use App\Queries\Posts\RecentlyDeletedPostsQuery;

/*
 * Top-level PR-G safety invariant: ordinary reversible Hidden content is
 * NEVER purge material. Hide -> years pass -> every cleanup command runs
 * on its default schedule -> the rows still exist, moderation_removed_at
 * is still null and Restore still works.
 */

it('never auto-purges reversible Hidden content, and restore keeps working years later', function () {
    config([
        'content_lifecycle.comments.author_delete_retention_days' => 30,
        'content_lifecycle.moderation.content_retention_days' => null,
    ]);

    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->published()->create();
    app(HidePostAction::class)->handle($moderator, $post);

    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create();
    app(HideCommentAction::class)->handle($moderator, $comment);

    $this->travel(365 * 3)->days();

    $this->artisan('posts:purge')->assertSuccessful();
    $this->artisan('comments:purge-deleted')->assertSuccessful();
    $this->artisan('moderation:purge-content')->assertSuccessful();

    $freshPost = $post->fresh();
    $freshComment = $comment->fresh();

    expect($freshPost)->not->toBeNull()
        ->and($freshPost->status)->toBe(PostStatus::Hidden)
        ->and($freshPost->moderation_removed_at)->toBeNull()
        ->and($freshComment)->not->toBeNull()
        ->and($freshComment->status)->toBe(CommentStatus::Hidden)
        ->and($freshComment->moderation_removed_at)->toBeNull();

    // Restore still works after all that time.
    app(RestorePostAction::class)->handle($moderator, $freshPost);
    app(RestoreCommentAction::class)->handle($moderator, $freshComment);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($comment->fresh()->status)->toBe(CommentStatus::Visible);
});

it('keeps finalized content retained forever while moderation retention stays disabled', function () {
    config(['content_lifecycle.moderation.content_retention_days' => null]);

    $admin = User::factory()->admin()->create();

    $post = Post::factory()->hidden()->create();
    app(FinalizePostRemovalAction::class)->handle($admin, $post, 'reason');

    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    app(FinalizeCommentRemovalAction::class)->handle($admin, $comment, 'reason');

    $this->travel(365 * 3)->days();

    $this->artisan('moderation:purge-content')->assertSuccessful();

    expect(Post::query()->find($post->id))->not->toBeNull()
        ->and(Comment::query()->find($comment->id))->not->toBeNull();
});

it('keeps the author Recently Deleted surface free of moderation-hidden and finalized posts', function () {
    $owner = User::factory()->create();

    $hidden = Post::factory()->hidden()->for($owner)->create();
    $finalized = Post::factory()->hidden()->for($owner)->create();
    app(FinalizePostRemovalAction::class)->handle(
        User::factory()->admin()->create(),
        $finalized,
        'reason',
    );

    $listed = app(RecentlyDeletedPostsQuery::class)->forOwner($owner);

    expect($listed->pluck('id'))->not->toContain($hidden->id)
        ->and($listed->pluck('id'))->not->toContain($finalized->id);
});

it('keeps public rendering of finalized comments identical to ordinary hidden ones', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    $root = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Hidden]);
    Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $root->id]);

    app(FinalizeCommentRemovalAction::class)->handle($admin, $root, 'reason');

    // Structural tombstone semantics unchanged: the root renders as
    // [comment removed by moderator], nothing leaks the finalization.
    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);

    expect($roots)->toHaveCount(1)
        ->and($roots->first()->isStructuralTombstone())->toBeTrue()
        ->and($roots->first()->isModeratorHidden())->toBeTrue();

    $this->get(route('posts.show', $post->id))
        ->assertOk()
        ->assertDontSee('Removal finalized')
        ->assertDontSee('moderation_removed_at');
});
