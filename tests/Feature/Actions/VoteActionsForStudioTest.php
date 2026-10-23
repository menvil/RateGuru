<?php

use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Votes\VoteCommentAction;
use App\Actions\Votes\VotePostAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The three vote actions as Content Studio's executor calls them: a new vote
 * dated as it is given — and as ever without a date — and every refusal said
 * as a stable reason, the voting limit with how soon it allows the next vote,
 * never read from the translated message.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/** What a vote action threw, or a failure when it threw nothing. */
function voteRefusal(Closure $vote): Throwable
{
    try {
        $vote();
    } catch (CannotVoteException|CannotVoteCommentException|CannotVoteForRatingOptionException $refusal) {
        return $refusal;
    }

    throw new RuntimeException('The vote was not refused.');
}

it('dates a new vote on a post, a comment or for a rating option as given, and as ever when nothing is given', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $at = CarbonImmutable::parse('2025-06-10 10:01:00');
    [$dated, $plain] = User::factory()->count(2)->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);
    [$group, [$option]] = votableRatingQuestion();

    foreach ([$dated, $plain] as $user) {
        $date = $user->is($dated) ? $at : null;
        app(VotePostAction::class)->handle($user, $post, VoteType::Down, $date);
        app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up, $date);
        app(VoteRatingOptionAction::class)->handle($user, $post, $option, $date);
    }

    foreach ([PostVote::class, CommentVote::class, RatingVote::class] as $model) {
        $votes = $model::query()->get()->keyBy('user_id');

        expect($votes[$dated->id]->created_at->toDateTimeString())->toBe('2025-06-10 10:01:00', $model)
            ->and($votes[$dated->id]->updated_at->toDateTimeString())->toBe('2025-06-10 10:01:00', $model)
            ->and($votes[$plain->id]->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00', $model)
            ->and($votes[$plain->id]->updated_at->toDateTimeString())->toBe('2026-10-20 10:00:00', $model);
    }

    expect($post->fresh())->downvotes_count->toBe(2)
        ->and($comment->fresh())->upvotes_count->toBe(2)
        ->and(RatingVote::query()->where('rating_group_id', $group->id)->count())->toBe(2);
});

it('keeps the date an answer was first given when it changes, whatever date is given', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    [, [$first, $second]] = votableRatingQuestion();

    app(VoteRatingOptionAction::class)->handle($user, $post, $first);
    app(VoteRatingOptionAction::class)->handle($user, $post, $second, CarbonImmutable::parse('2025-06-10 10:01:00'));

    expect(RatingVote::query()->sole())
        ->rating_option_id->toBe($second->id)
        ->and(RatingVote::query()->sole()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('says the voting limit as a reason of its own, with how soon the next vote is allowed', function (Closure $vote) {
    config(['rate_limits.vote.max_attempts' => 1, 'rate_limits.vote.decay_seconds' => 60]);
    $user = User::factory()->create();
    $vote($user);

    $refusal = voteRefusal(fn () => $vote($user));

    expect($refusal->isRateLimited())->toBeTrue()
        ->and($refusal->retryAfterSeconds())->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($refusal->getMessage())->toBe(__('ui.rate_limit.voting'));
})->with([
    'on a post' => [fn (User $user) => app(VotePostAction::class)->handle($user, Post::factory()->published()->create(), VoteType::Up)],
    'on a comment' => [fn (User $user) => app(VoteCommentAction::class)->handle($user, Comment::factory()->create(['post_id' => Post::factory()->published()->create()->id]), VoteType::Up)],
    'for a rating option' => [fn (User $user) => app(VoteRatingOptionAction::class)->handle($user, Post::factory()->published()->create(), votableRatingQuestion()[1][0])],
]);

it('says why else a vote is refused as a stable reason, the message as before', function (Closure $vote, string $reason, string $message) {
    $refusal = voteRefusal($vote);

    expect($refusal->getMessage())->toBe(__($message))
        ->and($refusal->isRateLimited())->toBeFalse()
        ->and($refusal->retryAfterSeconds())->toBeNull()
        ->and($refusal->{$reason}())->toBeTrue();
})->with([
    'a post hidden' => [fn () => app(VotePostAction::class)->handle(User::factory()->create(), Post::factory()->create(['status' => PostStatus::Hidden]), VoteType::Up), 'isPostUnavailable', 'ui.voting.errors.post_not_public'],
    'a banned voter on a post' => [fn () => app(VotePostAction::class)->handle(User::factory()->create(['status' => UserStatus::Banned]), Post::factory()->published()->create(), VoteType::Up), 'isNotAllowed', 'ui.voting.errors.not_allowed_post'],
    'a comment hidden' => [fn () => app(VoteCommentAction::class)->handle(User::factory()->create(), Comment::factory()->create(['status' => CommentStatus::Hidden]), VoteType::Up), 'isCommentUnavailable', 'ui.voting.errors.comment_not_visible'],
    'a limited voter on a comment' => [fn () => app(VoteCommentAction::class)->handle(User::factory()->create(['status' => UserStatus::Limited]), Comment::factory()->create(), VoteType::Up), 'isNotAllowed', 'ui.voting.errors.not_allowed_comment'],
    'an option archived' => [function () {
        [, [$option]] = votableRatingQuestion();
        $option->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        app(VoteRatingOptionAction::class)->handle(User::factory()->create(), Post::factory()->published()->create(), $option);
    }, 'isOptionUnavailable', 'ui.voting.errors.option_inactive'],
    'a rating question no longer active' => [function () {
        [$group, [$option]] = votableRatingQuestion();
        $group->forceFill(['is_active' => false])->save();
        app(VoteRatingOptionAction::class)->handle(User::factory()->create(), Post::factory()->published()->create(), $option);
    }, 'isOptionUnavailable', 'ui.voting.errors.group_inactive'],
    'a post hidden for a rating' => [fn () => app(VoteRatingOptionAction::class)->handle(User::factory()->create(), Post::factory()->create(['status' => PostStatus::Hidden]), votableRatingQuestion()[1][0]), 'isPostUnavailable', 'ui.voting.errors.rating_post_not_public'],
]);
