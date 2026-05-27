<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculateCommentCountersAction;
use App\Enums\VoteType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\DB;

final class VoteCommentAction
{
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
            $lockedComment = Comment::query()
                ->whereKey($comment->id)
                ->lockForUpdate()
                ->firstOrFail();

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
