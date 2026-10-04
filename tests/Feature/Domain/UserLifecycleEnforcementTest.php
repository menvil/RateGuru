<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Follows\FollowAuthorAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Reports\ReportContentAction;
use App\Actions\Votes\VoteCommentAction;
use App\Actions\Votes\VotePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Posts\CannotCreatePostException;
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

/*
 * Application-level enforcement of the lifecycle capability matrix: every
 * participation action must reject users in a restricted lifecycle state
 * with its existing domain exception, and must keep working for active
 * users. Restricted states share one dataset because Limited, Banned and
 * Shadowbanned currently have an identical enforced capability profile —
 * see docs/architecture/user-lifecycle.md.
 */

dataset('restricted users', [
    'limited' => [fn (): User => User::factory()->limited()->create()],
    'banned' => [fn (): User => User::factory()->banned()->create()],
    'shadowbanned' => [fn (): User => User::factory()->shadowbanned()->create()],
    'deleted tombstone' => [fn (): User => User::factory()->tombstoned()->create()],
]);

it('rejects post creation for restricted lifecycle states', function (User $user) {
    try {
        app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'Blocked dish'));
        $this->fail('Expected CannotCreatePostException.');
    } catch (CannotCreatePostException) {
        expect(Post::query()->count())->toBe(0);
    }
})->with('restricted users');

it('rejects commenting for restricted lifecycle states', function (User $user) {
    $post = Post::factory()->published()->create();

    try {
        app(AddCommentAction::class)->handle($user, $post, 'Blocked comment');
        $this->fail('Expected CannotCommentException.');
    } catch (CannotCommentException) {
        expect(Comment::query()->count())->toBe(0);
    }
})->with('restricted users');

it('rejects post voting for restricted lifecycle states', function (User $user) {
    $post = Post::factory()->published()->create();

    try {
        app(VotePostAction::class)->handle($user, $post, VoteType::Up);
        $this->fail('Expected CannotVoteException.');
    } catch (CannotVoteException) {
        expect(PostVote::query()->count())->toBe(0);
    }
})->with('restricted users');

it('rejects comment voting for restricted lifecycle states', function (User $user) {
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

    try {
        app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up);
        $this->fail('Expected CannotVoteCommentException.');
    } catch (CannotVoteCommentException) {
        expect(CommentVote::query()->count())->toBe(0);
    }
})->with('restricted users');

it('rejects rating votes for restricted lifecycle states', function (User $user) {
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();

    try {
        app(VoteRatingOptionAction::class)->handle($user, $post, $option);
        $this->fail('Expected CannotVoteForRatingOptionException.');
    } catch (CannotVoteForRatingOptionException) {
        expect(RatingVote::query()->count())->toBe(0);
    }
})->with('restricted users');

it('rejects reporting for restricted lifecycle states', function (User $user) {
    $post = Post::factory()->published()->create();

    try {
        app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);
        $this->fail('Expected CannotReportContentException.');
    } catch (CannotReportContentException) {
        expect(Report::query()->count())->toBe(0);
    }
})->with('restricted users');

/*
 * Follower-side lifecycle gate. Deliberate PR-A behavior change: before the
 * centralized contract, FollowAuthorAction only validated the author's
 * status, so restricted users could still start following people. "New
 * participation restricted" now includes creating follows.
 */
it('rejects following for restricted lifecycle states', function (User $user) {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $author = User::factory()->create();

    try {
        app(FollowAuthorAction::class)->handle($user, $author);
        $this->fail('Expected CannotFollowAuthorException.');
    } catch (CannotFollowAuthorException $e) {
        expect($e->getMessage())->toBe('Your account is not allowed to follow authors.');
        expect(Follow::query()->count())->toBe(0);
    }
})->with('restricted users');

it('rejects following an author in a restricted lifecycle state', function (User $author) {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();

    try {
        app(FollowAuthorAction::class)->handle($follower, $author);
        $this->fail('Expected CannotFollowAuthorException.');
    } catch (CannotFollowAuthorException $e) {
        expect($e->getMessage())->toBe('This author cannot be followed.');
        expect(Follow::query()->count())->toBe(0);
    }
})->with('restricted users');

it('permits every participation action for an active user', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->published()->for($author)->create();
    $comment = Comment::factory()->for($author)->create([
        'post_id' => $post->id,
        'status' => CommentStatus::Visible,
    ]);
    $option = RatingOption::factory()->create();

    $created = app(CreatePostAction::class)->handle($user, new CreatePostData(title: 'Allowed dish'));
    app(AddCommentAction::class)->handle($user, $post, 'Allowed comment');
    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
    app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up);
    app(VoteRatingOptionAction::class)->handle($user, $post, $option);
    app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);
    app(FollowAuthorAction::class)->handle($user, $author);

    expect($created->exists)->toBeTrue();
    $this->assertDatabaseHas('comments', ['user_id' => $user->id, 'post_id' => $post->id]);
    $this->assertDatabaseHas('post_votes', ['user_id' => $user->id, 'post_id' => $post->id]);
    $this->assertDatabaseHas('comment_votes', ['user_id' => $user->id, 'comment_id' => $comment->id]);
    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_option_id' => $option->id,
    ]);
    $this->assertDatabaseHas('reports', ['reporter_id' => $user->id, 'target_id' => $post->id]);
    $this->assertDatabaseHas('follows', ['follower_id' => $user->id, 'author_id' => $author->id]);
});
