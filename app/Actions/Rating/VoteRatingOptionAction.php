<?php

namespace App\Actions\Rating;

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Post;
use App\Models\RatingGroup;
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
            $group = RatingGroup::query()
                ->lockForUpdate()
                ->findOrFail($option->rating_group_id);
            $lockedOption = $group->options()
                ->lockForUpdate()
                ->findOrFail($option->id);

            if (! $lockedOption->is_active) {
                throw CannotVoteForRatingOptionException::becauseOptionIsInactive();
            }

            if (! $group->is_active) {
                throw CannotVoteForRatingOptionException::becauseGroupIsInactive();
            }

            $existingOptionId = RatingVote::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->where('rating_group_id', $group->id)
                ->value('rating_option_id');

            RatingVote::query()->upsert(
                [[
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'rating_group_id' => $group->id,
                    'rating_option_id' => $lockedOption->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'post_id', 'rating_group_id'],
                ['rating_option_id', 'updated_at'],
            );

            return (int) $existingOptionId !== (int) $lockedOption->id;
        });

        if ($changed) {
            $this->postListCache->invalidateForPost($post);
        }
    }
}
