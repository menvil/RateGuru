<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Enums\OriginType;
use App\Exceptions\Votes\CannotVoteOriginException;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VoteOriginAction
{
    public function __construct(
        private readonly RecalculatePostCountersAction $recalculatePostCounters,
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
                // clearing requires an explicit separate action.
                if ($existingVote->origin === $origin) {
                    return;
                }

                $existingVote->update(['origin' => $origin]);

                return;
            }

            OriginVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'origin' => $origin,
            ]);
        });

        $this->recalculatePostCounters->handle($post->fresh());
    }
}
