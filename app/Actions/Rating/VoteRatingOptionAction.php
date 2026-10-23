<?php

namespace App\Actions\Rating;

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Cache\PostListCacheManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class VoteRatingOptionAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly ActionRateLimiter $rateLimiter,
        private readonly PostListCacheManager $postListCache,
    ) {}

    /**
     * A member's answer to a rating question: the option chosen, in place of
     * one chosen before.
     *
     * @param  CarbonImmutable|null  $createdAt  the date a new answer takes as its own — Content Studio's executor only,
     *                                           for an answer it has found not given yet; never from a screen. An answer
     *                                           changed keeps its own `created_at` whatever is given.
     */
    public function handle(?User $user, Post $post, RatingOption $option, ?CarbonImmutable $createdAt = null): void
    {
        if ($user === null) {
            throw CannotVoteForRatingOptionException::becauseGuest();
        }

        if (! $user->canVote()) {
            throw CannotVoteForRatingOptionException::becauseUserIsNotAllowed();
        }

        if (! $post->canReceiveRatingVotes()) {
            throw CannotVoteForRatingOptionException::becausePostIsNotPublic();
        }

        if (! $user->can('vote', $post)) {
            throw CannotVoteForRatingOptionException::becauseOwnPost();
        }

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('vote', $user),
                maxAttempts: (int) config('rate_limits.vote.max_attempts'),
                decaySeconds: (int) config('rate_limits.vote.decay_seconds'),
                message: __('ui.rate_limit.voting'),
            );
        } catch (RateLimitExceededException $e) {
            throw CannotVoteForRatingOptionException::becauseRateLimited($e->getMessage(), $e->retryAfterSeconds());
        }

        $changed = DB::transaction(function () use ($user, $post, $option, $createdAt): bool {
            // Lock order: Actor User -> Post -> rating group -> option
            // (docs/architecture/user-lifecycle.md). Pre-checks ran on
            // possibly stale instances; the locked rows decide.
            $lockedActor = $this->lockActor($user);

            if ($lockedActor === null || ! $lockedActor->canVote()) {
                throw CannotVoteForRatingOptionException::becauseUserIsNotAllowed();
            }

            $lockedPost = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null || ! $lockedPost->canReceiveRatingVotes()) {
                throw CannotVoteForRatingOptionException::becausePostIsNotPublic();
            }

            // The option, or its question, deleted since it was read, or the
            // option moved to another question: none to vote for here, and no
            // other option in its place.
            $group = RatingGroup::query()
                ->lockForUpdate()
                ->find($option->rating_group_id);

            if ($group === null) {
                throw CannotVoteForRatingOptionException::becauseOptionIsUnavailable();
            }

            $lockedOption = $group->options()
                ->lockForUpdate()
                ->find($option->id);

            if ($lockedOption === null) {
                throw CannotVoteForRatingOptionException::becauseOptionIsUnavailable();
            }

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
                    'created_at' => $createdAt ?? now(),
                    'updated_at' => $createdAt ?? now(),
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
