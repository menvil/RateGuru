<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculateCommentCountersAction;
use App\Enums\VoteType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\DB;

final class VoteCommentAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly RecalculateCommentCountersAction $recalculateCommentCounters,
        private readonly ActionRateLimiter $rateLimiter,
    ) {}

    public function handle(?User $user, Comment $comment, VoteType $type): void
    {
        if ($user === null) {
            throw CannotVoteCommentException::becauseGuest();
        }

        if (! $user->canVote()) {
            throw CannotVoteCommentException::becauseUserIsNotAllowed();
        }

        if (! $comment->canReceiveVotes()) {
            throw CannotVoteCommentException::becauseCommentIsNotVisible();
        }

        if ((int) $comment->user_id === (int) $user->id) {
            throw CannotVoteCommentException::becauseOwnComment();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('vote', $user),
                maxAttempts: (int) config('rate_limits.vote.max_attempts'),
                decaySeconds: (int) config('rate_limits.vote.decay_seconds'),
                message: 'You are voting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotVoteCommentException::becauseRateLimited($e->getMessage());
        }

        DB::transaction(function () use ($user, $comment, $type): void {
            // Lock order: Actor User -> Post -> Comment -> vote rows; the
            // pre-checks ran on possibly stale instances. A still-Visible
            // comment beneath an author-deleted or Hidden post is not a
            // public interaction surface and must not accept votes.
            $lockedActor = $this->lockActor($user);

            if ($lockedActor === null || ! $lockedActor->canVote()) {
                throw CannotVoteCommentException::becauseUserIsNotAllowed();
            }

            $lockedPost = Post::withTrashed()
                ->whereKey($comment->post_id)
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null || ! $lockedPost->canReceiveVotes()) {
                throw CannotVoteCommentException::becauseCommentIsNotVisible();
            }

            $lockedComment = Comment::query()
                ->withTrashed()
                ->whereKey($comment->id)
                ->lockForUpdate()
                ->first();

            // The pre-transaction check ran on the caller's instance, which
            // may be stale: a hide or author delete can land in between.
            // Only the row re-read under lock is authoritative.
            if ($lockedComment === null || ! $lockedComment->canReceiveVotes()) {
                throw CannotVoteCommentException::becauseCommentIsNotVisible();
            }

            // Re-check the own-comment rule on the authoritative rows.
            if ((int) $lockedComment->user_id === (int) $lockedActor->id) {
                throw CannotVoteCommentException::becauseOwnComment();
            }

            $existingVote = CommentVote::query()
                ->where('comment_id', $lockedComment->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingVote !== null) {
                if ($existingVote->type === $type) {
                    $existingVote->delete();
                } else {
                    $existingVote->update(['type' => $type]);
                }
            } else {
                CommentVote::create([
                    'user_id' => $user->id,
                    'comment_id' => $lockedComment->id,
                    'type' => $type,
                ]);
            }

            $this->recalculateCommentCounters->handle($lockedComment->refresh());
        });
    }
}
