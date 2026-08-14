<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Follows\FollowAuthorAction;
use App\Actions\Follows\UnfollowAuthorAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\RestoreDeletedPostAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Profile\UpdateUserIdentityAction;
use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Reports\ReportContentAction;
use App\Actions\Votes\VoteCommentAction;
use App\Actions\Votes\VotePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\ReportReason;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Exceptions\Posts\CannotRestoreDeletedPostException;
use App\Exceptions\Profile\CannotUpdateProfileException;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Exceptions\Reports\CannotReportContentException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\ProjectSettings;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * PR-F stale-actor contract: a capability check on the caller's User
 * instance is never authoritative. An admin sanction can commit between
 * the pre-check and the write; every lifecycle-dependent action re-reads
 * and locks the actor row inside its transaction. These deterministic
 * sequential tests pin that: capture an Active instance, commit the
 * sanction behind its back, call the action with the stale object.
 */

function staleActiveUser(): User
{
    $user = User::factory()->create();

    // Sanction lands behind the instance's back: the in-memory object
    // still says Active, so every cheap pre-check passes.
    User::query()->whereKey($user->id)->update(['status' => UserStatus::Banned]);

    expect($user->status)->toBe(UserStatus::Active);

    return $user;
}

it('rejects post creation by a stale sanctioned actor', function () {
    $stale = staleActiveUser();

    expect(fn () => app(CreatePostAction::class)->handle($stale, new CreatePostData(title: 'Too late')))
        ->toThrow(CannotCreatePostException::class);

    expect(Post::query()->where('user_id', $stale->id)->count())->toBe(0);
});

it('rejects a comment by a stale sanctioned actor without notification', function () {
    Notification::fake();

    $stale = staleActiveUser();
    $post = Post::factory()->published()->create();

    expect(fn () => app(AddCommentAction::class)->handle($stale, $post, 'Too late'))
        ->toThrow(CannotCommentException::class);

    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

it('rejects a post vote by a stale sanctioned actor', function () {
    $stale = staleActiveUser();
    $post = Post::factory()->published()->create();

    expect(fn () => app(VotePostAction::class)->handle($stale, $post, VoteType::Up))
        ->toThrow(CannotVoteException::class);

    expect(PostVote::query()->count())->toBe(0)
        ->and($post->fresh()->upvotes_count)->toBe(0);
});

it('rejects a comment vote by a stale sanctioned actor', function () {
    $stale = staleActiveUser();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create();

    expect(fn () => app(VoteCommentAction::class)->handle($stale, $comment, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class);

    expect(CommentVote::query()->count())->toBe(0);
});

it('rejects a rating vote by a stale sanctioned actor', function () {
    $stale = staleActiveUser();
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();

    expect(fn () => app(VoteRatingOptionAction::class)->handle($stale, $post, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);

    expect(RatingVote::query()->count())->toBe(0);
});

it('rejects a report by a stale sanctioned reporter', function () {
    $stale = staleActiveUser();
    $post = Post::factory()->published()->create();

    expect(fn () => app(ReportContentAction::class)->handle($stale, $post, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0)
        ->and($post->fresh()->reports_count)->toBe(0)
        ->and($post->fresh()->needs_review)->toBeFalse();
});

it('rejects a follow when the stale follower was sanctioned', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $stale = staleActiveUser();
    $author = User::factory()->create();

    expect(fn () => app(FollowAuthorAction::class)->handle($stale, $author))
        ->toThrow(CannotFollowAuthorException::class);

    expect(Follow::query()->count())->toBe(0);
});

it('rejects a follow when the stale target was sanctioned', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $staleTarget = staleActiveUser();

    expect(fn () => app(FollowAuthorAction::class)->handle($follower, $staleTarget))
        ->toThrow(CannotFollowAuthorException::class);

    expect(Follow::query()->count())->toBe(0);
});

it('rejects an author post deletion by a stale sanctioned owner', function () {
    $stale = staleActiveUser();
    Post::query()->whereKey(($post = Post::factory()->published()->for($stale)->create())->id)->exists();

    expect(fn () => app(DeletePostAction::class)->handle($stale, $post))
        ->toThrow(CannotDeletePostException::class);

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeFalse();
});

it('rejects an author post restore by a stale sanctioned owner', function () {
    config(['posts.author_delete_retention_days' => 30]);

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    User::query()->whereKey($owner->id)->update(['status' => UserStatus::Limited]);

    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id)))
        ->toThrow(CannotRestoreDeletedPostException::class);

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
});

it('rejects an author comment deletion by a stale sanctioned owner', function () {
    $stale = staleActiveUser();
    $comment = Comment::factory()->for($stale)->for(Post::factory()->published(), 'post')->create();

    expect(fn () => app(DeleteCommentAction::class)->handle($stale, $comment))
        ->toThrow(CannotDeleteCommentException::class);

    expect(Comment::withTrashed()->findOrFail($comment->id)->trashed())->toBeFalse();
});

it('rejects a profile identity mutation by a stale sanctioned owner', function () {
    $stale = staleActiveUser();
    $originalName = $stale->name;

    expect(fn () => app(UpdateUserIdentityAction::class)->execute($stale, ['name' => 'New Name', 'email' => $stale->email]))
        ->toThrow(CannotUpdateProfileException::class);

    expect($stale->fresh()->name)->toBe($originalName);
});

it('rejects a user-target report through a stale instance after the target self-deleted', function () {
    $reporter = User::factory()->create();
    $staleTarget = User::factory()->create();

    // The tombstone commits behind the stale instance's back: the
    // pre-check still sees a living target, only the locked pair re-read
    // inside the transaction can catch it.
    app(AnonymizeUserAccountAction::class)->execute(User::findOrFail($staleTarget->id));

    expect($staleTarget->isTombstoned())->toBeFalse();

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $staleTarget, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('still allows a sanctioned user to unfollow', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();
    Follow::create(['follower_id' => $follower->id, 'author_id' => $author->id]);

    User::query()->whereKey($follower->id)->update(['status' => UserStatus::Banned]);

    app(UnfollowAuthorAction::class)->handle($follower->fresh(), $author);

    expect(Follow::query()->count())->toBe(0);
});
