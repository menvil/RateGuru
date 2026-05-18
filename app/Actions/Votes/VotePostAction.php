<?php

namespace App\Actions\Votes;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VotePostAction
{
    public function __construct(
        private readonly RecalculatePostCountersAction $recalculatePostCounters,
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

        DB::transaction(function () use ($user, $post, $type) {
            $existingVote = PostVote::query()
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingVote !== null) {
                if ($existingVote->type === $type) {
                    $existingVote->delete();

                    return;
                }

                $existingVote->update(['type' => $type]);

                return;
            }

            PostVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'type' => $type,
            ]);
        });

        $this->recalculatePostCounters->handle($post->fresh());
    }
}
