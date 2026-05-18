<?php

namespace App\Actions\Votes;

use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VotePostAction
{
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
                    $this->decrementCounter($post, $type);

                    return;
                }

                $oldType = $existingVote->type;

                $existingVote->update(['type' => $type]);

                $this->decrementCounter($post, $oldType);
                $this->incrementCounter($post, $type);

                return;
            }

            PostVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'type' => $type,
            ]);

            $this->incrementCounter($post, $type);
        });
    }

    private function incrementCounter(Post $post, VoteType $type): void
    {
        $post->increment($this->counterColumn($type));
    }

    private function decrementCounter(Post $post, VoteType $type): void
    {
        $column = $this->counterColumn($type);

        // Atomic guarded decrement: never drops below zero even under
        // concurrent votes, since the comparison and update are one statement.
        Post::query()
            ->whereKey($post->id)
            ->where($column, '>', 0)
            ->decrement($column);
    }

    private function counterColumn(VoteType $type): string
    {
        return match ($type) {
            VoteType::Up => 'upvotes_count',
            VoteType::Down => 'downvotes_count',
        };
    }
}
