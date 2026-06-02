<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\VoteType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Cache\PostListCacheManager;
use Illuminate\Support\Facades\DB;

final class VotePostAction
{
    public function __construct(
        private readonly RecalculatePostCountersAction $recalculatePostCounters,
        private readonly RecalculatePostScoreAction $recalculatePostScore,
        private readonly ActionRateLimiter $rateLimiter,
        private readonly PostListCacheManager $postListCache,
    ) {}

    public function handle(?User $user, Post $post, VoteType $type): void
    {
        if ($user === null) {
            throw CannotVoteException::becauseGuest();
        }

        if (! $user->canVote()) {
            throw CannotVoteException::becauseUserIsNotAllowed();
        }

        if (! $post->canReceiveVotes()) {
            throw CannotVoteException::becausePostIsNotPublic();
        }

        if ((int) $post->user_id === (int) $user->id) {
            throw CannotVoteException::becauseOwnPost();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('vote', $user),
                maxAttempts: (int) config('rate_limits.vote.max_attempts'),
                decaySeconds: (int) config('rate_limits.vote.decay_seconds'),
                message: 'You are voting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotVoteException::becauseRateLimited($e->getMessage());
        }

        DB::transaction(function () use ($user, $post, $type) {
            $existingVote = PostVote::query()
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingVote !== null) {
                if ($existingVote->type === $type) {
                    $existingVote->delete();
                } else {
                    $existingVote->delete();
                }
            } else {
                PostVote::create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'type' => $type,
                ]);
            }

            // Recalculate inside the transaction so a recalc failure rolls
            // back the vote and counters never diverge from post_votes.
            $this->recalculatePostCounters->handle($post->refresh());
            $this->recalculatePostScore->handle($post);
        });

        $this->postListCache->invalidateForPost($post);
    }
}
