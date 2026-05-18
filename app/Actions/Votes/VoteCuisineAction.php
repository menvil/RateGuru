<?php

namespace App\Actions\Votes;

use App\Enums\CuisineType;
use App\Exceptions\Votes\CannotVoteCuisineException;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VoteCuisineAction
{
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
                // clearing requires an explicit separate action.
                if ($existingVote->cuisine === $cuisine) {
                    return;
                }

                $existingVote->update(['cuisine' => $cuisine]);

                return;
            }

            CuisineVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'cuisine' => $cuisine,
            ]);
        });
    }

    private function isValidVoteCuisine(CuisineType $cuisine): bool
    {
        return in_array($cuisine, [
            CuisineType::Italian,
            CuisineType::Asian,
            CuisineType::American,
            CuisineType::Mexican,
            CuisineType::Other,
        ], true);
    }
}
