<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Enums\CuisineType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Votes\CannotVoteCuisineException;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\DB;

final class VoteCuisineAction
{
    public function __construct(
        private readonly RecalculatePostCountersAction $recalculatePostCounters,
        private readonly ActionRateLimiter $rateLimiter,
    ) {}

    public function handle(?User $user, Post $post, CuisineType $cuisine): void
    {
        if ($user === null) {
            throw CannotVoteCuisineException::becauseGuest();
        }

        if (! $user->canVote()) {
            throw CannotVoteCuisineException::becauseUserIsNotAllowed();
        }

        if (! $this->isValidVoteCuisine($cuisine)) {
            throw CannotVoteCuisineException::becauseCuisineIsInvalid();
        }

        if (! $post->canReceiveVotes()) {
            throw CannotVoteCuisineException::becausePostIsNotPublic();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('vote', $user),
                maxAttempts: (int) config('rate_limits.vote.max_attempts'),
                decaySeconds: (int) config('rate_limits.vote.decay_seconds'),
                message: 'You are voting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotVoteCuisineException::becauseRateLimited($e->getMessage());
        }

        DB::transaction(function () use ($user, $post, $cuisine) {
            $existingVote = CuisineVote::query()
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingVote !== null) {
                // Product decision (Phase 15): clicking the already-selected
                // cuisine keeps it selected. It is a no-op — the vote is NOT
                // cleared. Cuisine is a classification choice, not a like;
                // clearing requires an explicit separate action. Skip the
                // recalculation entirely since nothing changed.
                if ($existingVote->cuisine === $cuisine) {
                    return;
                }

                $existingVote->update(['cuisine' => $cuisine]);
            } else {
                CuisineVote::create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'cuisine' => $cuisine,
                ]);
            }

            // Recalculate inside the transaction so a recalc failure rolls
            // back the vote. refresh() returns the non-null model (fresh()
            // is nullable and would not satisfy the handle() signature).
            $this->recalculatePostCounters->handle($post->refresh());
        });
    }

    private function isValidVoteCuisine(CuisineType $cuisine): bool
    {
        return in_array($cuisine, CuisineType::votable(), true);
    }
}
