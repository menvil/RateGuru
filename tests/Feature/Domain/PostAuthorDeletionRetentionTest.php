<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\RestoreDeletedPostAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Feed\FeedQuery;
use App\Queries\SavedPosts\SavedPostsQuery;
use App\Queries\UserPublicPostsQuery;

beforeEach(function () {
    config(['posts.author_delete_retention_days' => 30]);
});

it('keeps the entire graph in the database while hiding the post from every public surface', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->withImage()->create();

    $post->tags()->attach(Tag::factory()->create());

    $comment = Comment::factory()->create(['post_id' => $post->id]);
    $reply = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $comment->id]);
    CommentVote::create(['comment_id' => $comment->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);
    PostVote::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);

    $option = RatingOption::factory()->create();
    RatingVote::factory()->create(['post_id' => $post->id, 'rating_option_id' => $option->id]);

    $saver = User::factory()->create();
    PostSave::create(['post_id' => $post->id, 'user_id' => $saver->id]);

    $post->authorAnswers()->create([
        'rating_group_id' => $option->rating_group_id,
        'rating_option_id' => $option->id,
    ]);

    Report::factory()->resolved()->create(['target_type' => Post::class, 'target_id' => $post->id]);

    app(DeletePostAction::class)->handle($owner, $post);

    // Storage shape: recoverable row, exact source captured.
    $fresh = Post::withTrashed()->findOrFail($post->id);
    expect($fresh->status)->toBe(PostStatus::Deleted)
        ->and($fresh->deleted_from_status)->toBe(PostStatus::Published)
        ->and($fresh->deleted_at)->not->toBeNull();

    // Every child row survives retention untouched.
    expect(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(2)
        ->and(CommentVote::query()->count())->toBe(1)
        ->and(PostVote::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(RatingVote::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(PostSave::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and($fresh->authorAnswers()->count())->toBe(1)
        ->and(Report::query()->count())->toBe(1)
        ->and(MediaAsset::withTrashed()->findOrFail($fresh->image_asset_id)->trashed())->toBeFalse();

    // Public surfaces: detail 404, feed, public profile and saved list all
    // exclude the deleted post.
    $this->get(route('posts.show', $post->id))->assertNotFound();

    expect(app(FeedQuery::class)->get()->pluck('id'))
        ->not->toContain($post->id);

    expect(app(UserPublicPostsQuery::class)->forProfile($owner->fresh())->getCollection()->pluck('id'))
        ->not->toContain($post->id);

    $this->actingAs($saver);
    expect(app(SavedPostsQuery::class)->forUser($saver)->getCollection()->pluck('id'))
        ->not->toContain($post->id);
});

it('preserves PR-D comment tombstone semantics across delete and restore', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    // Normal comment + reply.
    $normal = Comment::factory()->create(['post_id' => $post->id]);
    Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $normal->id]);

    // Author-deleted parent tombstone with a surviving reply.
    $tombstoneParent = Comment::factory()->create(['post_id' => $post->id]);
    $survivingReply = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $tombstoneParent->id]);
    app(DeleteCommentAction::class)->handle($tombstoneParent->user, $tombstoneParent);

    // Moderator-hidden comment.
    $hidden = Comment::factory()->create(['post_id' => $post->id]);
    app(HideCommentAction::class)->handle(User::factory()->moderator()->create(), $hidden);

    $snapshot = fn () => Comment::withTrashed()
        ->where('post_id', $post->id)
        ->orderBy('id')
        ->get(['id', 'status', 'deleted_at', 'parent_id'])
        ->map(fn (Comment $comment) => [
            'id' => $comment->id,
            'status' => $comment->status->value,
            'deleted' => $comment->deleted_at !== null,
            'parent_id' => $comment->parent_id,
        ])->all();

    $before = $snapshot();

    app(DeletePostAction::class)->handle($owner, $post);
    expect($snapshot())->toBe($before);

    app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id));
    expect($snapshot())->toBe($before);

    // Tombstone semantics intact after restore.
    $restoredParent = Comment::withTrashed()->findOrFail($tombstoneParent->id);
    expect($restoredParent->isStructuralTombstone())->toBeTrue()
        ->and($survivingReply->fresh()->trashed())->toBeFalse()
        ->and(Comment::withTrashed()->findOrFail($hidden->id)->status)->toBe(CommentStatus::Hidden);
});
