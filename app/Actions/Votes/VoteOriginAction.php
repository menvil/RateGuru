<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Enums\OriginType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Votes\CannotVoteOriginException;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\DB;

final class VoteOriginAction
{
    public function __construct(
        private readonly RecalculatePostCountersAction $recalculatePostCounters,
        private readonly ActionRateLimiter $rateLimiter,
    ) {}

    public function handle(?User $user, Post $post, OriginType $origin): void
    {
        if ($user === null) {
            throw CannotVoteOriginException::becauseGuest();
        }

        if (! $user->canVote()) {
            throw CannotVoteOriginException::becauseUserIsNotAllowed();
        }

        if ($origin === OriginType::Unknown) {
            throw CannotVoteOriginException::becauseOriginIsInvalid();
        }

        if (! $post->canReceiveVotes()) {
            throw CannotVoteOriginException::becausePostIsNotPublic();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('vote', $user),
                maxAttempts: (int) config('rate_limits.vote.max_attempts'),
                decaySeconds: (int) config('rate_limits.vote.decay_seconds'),
                message: 'You are voting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotVoteOriginException::becauseRateLimited($e->getMessage());
        }

        DB::transaction(function () use ($user, $post, $origin) {
            $existingVote = OriginVote::query()
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingVote !== null) {
                // Product decision (Phase 14): clicking the already-selected
                // origin keeps it selected. It is a no-op — the vote is NOT
                // cleared. Origin is a classification choice, not a like;
                // clearing requires an explicit separate action. Skip the
                // recalculation entirely since nothing changed.
                if ($existingVote->origin === $origin) {
                    return;
                }

                $existingVote->update(['origin' => $origin]);
            } else {
                OriginVote::create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'origin' => $origin,
                ]);
            }

            // Recalculate inside the transaction so a recalc failure rolls
            // back the vote and counters never diverge from origin_votes.
            $this->recalculatePostCounters->handle($post->refresh());
        });
    }
}
