<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\TogglePostSaveAction;
use App\Actions\Posts\UnsavePostAction;
use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Reports\ReportContentAction;
use App\Actions\Votes\VotePostAction;
use App\Enums\ReportReason;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Exceptions\Reports\CannotReportContentException;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * PR-E contract: a helper check on a stale model is never enough. Every
 * action writing state against a Post re-reads the authoritative row
 * withTrashed under lockForUpdate and revalidates lifecycle before writing.
 * The stale window: instance loaded while Published, row author-deleted (or
 * hidden) before the action runs.
 */

function stalePublishedPost(): array
{
    $owner = User::factory()->create();
    $stale = Post::factory()->published()->for($owner)->create();

    return [$owner, $stale];
}

function authorDeleteFresh(User $owner, Post $stale): void
{
    app(DeletePostAction::class)->handle($owner, Post::findOrFail($stale->id));
    // The caller keeps its stale Published attributes.
    expect($stale->deleted_at)->toBeNull();
}

function moderatorHideFresh(Post $stale): void
{
    app(HidePostAction::class)->handle(
        User::factory()->moderator()->create(),
        Post::findOrFail($stale->id),
    );
}

// ------------------------------------------------------------ post votes

it('rejects a post vote through a stale instance after author deletion', function () {
    [$owner, $stale] = stalePublishedPost();
    $voter = User::factory()->create();
    authorDeleteFresh($owner, $stale);

    expect(fn () => app(VotePostAction::class)->handle($voter, $stale, VoteType::Up))
        ->toThrow(CannotVoteException::class);

    expect(PostVote::query()->where('post_id', $stale->id)->count())->toBe(0);
});

it('rejects a post vote through a stale instance after moderation hide', function () {
    [, $stale] = stalePublishedPost();
    $voter = User::factory()->create();
    moderatorHideFresh($stale);

    expect(fn () => app(VotePostAction::class)->handle($voter, $stale, VoteType::Up))
        ->toThrow(CannotVoteException::class);

    expect(PostVote::query()->where('post_id', $stale->id)->count())->toBe(0);
});

// ---------------------------------------------------------- rating votes

it('rejects a rating vote through a stale instance after author deletion', function () {
    [$owner, $stale] = stalePublishedPost();
    $voter = User::factory()->create();
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $option = $group->options()->first()
        ?? RatingOption::factory()->create(['rating_group_id' => $group->id, 'is_active' => true]);

    authorDeleteFresh($owner, $stale);

    expect(fn () => app(VoteRatingOptionAction::class)->handle($voter, $stale, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);

    expect(RatingVote::query()->where('post_id', $stale->id)->count())->toBe(0);
});

it('rejects a rating vote through a stale instance after moderation hide', function () {
    [, $stale] = stalePublishedPost();
    $voter = User::factory()->create();
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $option = $group->options()->first()
        ?? RatingOption::factory()->create(['rating_group_id' => $group->id, 'is_active' => true]);

    moderatorHideFresh($stale);

    expect(fn () => app(VoteRatingOptionAction::class)->handle($voter, $stale, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);

    expect(RatingVote::query()->where('post_id', $stale->id)->count())->toBe(0);
});

// -------------------------------------------------------------- comments

it('rejects a comment through a stale instance after author deletion, with no notification', function () {
    Notification::fake();

    [$owner, $stale] = stalePublishedPost();
    $commenter = User::factory()->create();
    authorDeleteFresh($owner, $stale);

    expect(fn () => app(AddCommentAction::class)->handle($commenter, $stale, 'Too late'))
        ->toThrow(CannotCommentException::class);

    expect(Comment::withTrashed()->where('post_id', $stale->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

it('rejects a reply through a stale post instance even with a live parent comment', function () {
    [$owner, $stale] = stalePublishedPost();
    $parent = Comment::factory()->create(['post_id' => $stale->id]);
    $replier = User::factory()->create();

    authorDeleteFresh($owner, $stale);

    expect(fn () => app(AddCommentAction::class)->handle($replier, $stale, 'Reply', $parent))
        ->toThrow(CannotCommentException::class);

    expect(Comment::withTrashed()->where('post_id', $stale->id)->count())->toBe(1);
});

it('rejects a comment through a stale instance after moderation hide', function () {
    [, $stale] = stalePublishedPost();
    $commenter = User::factory()->create();
    moderatorHideFresh($stale);

    expect(fn () => app(AddCommentAction::class)->handle($commenter, $stale, 'Too late'))
        ->toThrow(CannotCommentException::class);

    expect(Comment::withTrashed()->where('post_id', $stale->id)->count())->toBe(0);
});

// --------------------------------------------------------------- reports

it('rejects a report through a stale instance after author deletion', function () {
    [$owner, $stale] = stalePublishedPost();
    $reporter = User::factory()->create();
    authorDeleteFresh($owner, $stale);

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $stale, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0)
        ->and(Post::withTrashed()->findOrFail($stale->id)->reports_count)->toBe(0);
});

it('rejects a report through a stale instance after moderation hide', function () {
    [, $stale] = stalePublishedPost();
    $reporter = User::factory()->create();
    moderatorHideFresh($stale);

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $stale, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('rejects reporting a hidden or author-deleted post directly', function () {
    $reporter = User::factory()->create();

    $hidden = Post::factory()->hidden()->create();
    expect(fn () => app(ReportContentAction::class)->handle($reporter, $hidden, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    $deleted = Post::factory()->authorDeleted()->create();
    expect(fn () => app(ReportContentAction::class)->handle($reporter, Post::withTrashed()->findOrFail($deleted->id), ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

// ----------------------------------------------------------------- saves

it('rejects a save through a stale instance after author deletion', function () {
    [$owner, $stale] = stalePublishedPost();
    $saver = User::factory()->create();
    authorDeleteFresh($owner, $stale);

    expect(fn () => app(TogglePostSaveAction::class)->handle($saver, $stale))
        ->toThrow(CannotSavePostException::class);

    expect(PostSave::query()->where('post_id', $stale->id)->count())->toBe(0);
});

it('rejects a save through a stale instance after moderation hide', function () {
    [, $stale] = stalePublishedPost();
    $saver = User::factory()->create();
    moderatorHideFresh($stale);

    expect(fn () => app(TogglePostSaveAction::class)->handle($saver, $stale))
        ->toThrow(CannotSavePostException::class);

    expect(PostSave::query()->where('post_id', $stale->id)->count())->toBe(0);
});

it('rejects an unsave mutation through a stale instance after moderation hide', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    [, $stale] = stalePublishedPost();
    $saver = User::factory()->create();
    PostSave::create(['user_id' => $saver->id, 'post_id' => $stale->id]);

    moderatorHideFresh($stale);

    expect(fn () => app(UnsavePostAction::class)->handle($saver, $stale))
        ->toThrow(CannotSavePostException::class);

    expect(PostSave::query()->where('post_id', $stale->id)->count())->toBe(1);
});

it('rejects an unsave mutation against a deleted post, keeping the row for purge', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    [$owner, $stale] = stalePublishedPost();
    $saver = User::factory()->create();
    PostSave::create(['user_id' => $saver->id, 'post_id' => $stale->id]);

    authorDeleteFresh($owner, $stale);

    expect(fn () => app(UnsavePostAction::class)->handle($saver, $stale))
        ->toThrow(CannotSavePostException::class);

    // The save row survives retention; only the final purge removes it.
    expect(PostSave::query()->where('post_id', $stale->id)->count())->toBe(1);
});
