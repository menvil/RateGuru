<?php

namespace App\Actions\Rating;

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Post;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Cache\PostListCacheManager;
use Illuminate\Support\Facades\DB;

final class VoteRatingOptionAction
{
    public function __construct(
        private readonly ActionRateLimiter $rateLimiter,
        private readonly PostListCacheManager $postListCache,
    ) {}

    public function handle(?User $user, Post $post, RatingOption $option): void
    {
        if ($user === null) {
            throw CannotVoteForRatingOptionException::becauseGuest();
        }

        if (! $user->canVote()) {
            throw CannotVoteForRatingOptionException::becauseUserIsNotAllowed();
        }

        if (! $post->canReceiveVotes()) {
            throw CannotVoteForRatingOptionException::becausePostIsNotPublic();
        }

        if ((int) $post->user_id === (int) $user->id) {
            throw CannotVoteForRatingOptionException::becauseOwnPost();
        }

        $option = RatingOption::query()
            ->with('group')
            ->findOrFail($option->id);

        if (! $option->is_active) {
            throw CannotVoteForRatingOptionException::becauseOptionIsInactive();
        }

        if (! $option->group->is_active) {
            throw CannotVoteForRatingOptionException::becauseGroupIsInactive();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('vote', $user),
                maxAttempts: (int) config('rate_limits.vote.max_attempts'),
                decaySeconds: (int) config('rate_limits.vote.decay_seconds'),
                message: 'You are voting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotVoteForRatingOptionException::becauseRateLimited($e->getMessage());
        }

        $changed = DB::transaction(function () use ($user, $post, $option): bool {
            $vote = RatingVote::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->where('rating_group_id', $option->rating_group_id)
                ->lockForUpdate()
                ->first();

            if ($vote === null) {
                RatingVote::query()->create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'rating_group_id' => $option->rating_group_id,
                    'rating_option_id' => $option->id,
                ]);

                return true;
            }

            if ((int) $vote->rating_option_id === (int) $option->id) {
                return false;
            }

            $vote->update(['rating_option_id' => $option->id]);

            return true;
        });

        if ($changed) {
            $this->postListCache->invalidateForPost($post);
        }
    }
}
