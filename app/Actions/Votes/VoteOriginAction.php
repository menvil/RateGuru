<?php

namespace App\Actions\Votes;

use App\Enums\OriginType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VoteOriginAction
{
    public function handle(?User $user, Post $post, OriginType $origin): void
    {
        DB::transaction(function () use ($user, $post, $origin) {
            $existingVote = OriginVote::query()
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingVote !== null) {
                // Product decision (Phase 14): clicking the already-selected
                // origin keeps it selected. It is a no-op — the vote is NOT
                // cleared and counters are NOT changed. Origin is a
                // classification choice, not a like; clearing requires an
                // explicit separate action.
                if ($existingVote->origin === $origin) {
                    return;
                }

                $oldOrigin = $existingVote->origin;

                $existingVote->update(['origin' => $origin]);

                $this->decrementCounter($post, $oldOrigin);
                $this->incrementCounter($post, $origin);

                return;
            }

            OriginVote::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'origin' => $origin,
            ]);

            $this->incrementCounter($post, $origin);
        });
    }

    private function incrementCounter(Post $post, OriginType $origin): void
    {
        $column = $this->counterColumn($origin);

        if ($column !== null) {
            $post->increment($column);
        }
    }

    private function decrementCounter(Post $post, OriginType $origin): void
    {
        $column = $this->counterColumn($origin);

        if ($column === null) {
            return;
        }

        // Atomic guarded decrement: never drops below zero.
        Post::query()
            ->whereKey($post->id)
            ->where($column, '>', 0)
            ->decrement($column);
    }

    private function counterColumn(OriginType $origin): ?string
    {
        return match ($origin) {
            OriginType::Homemade => 'homemade_votes_count',
            OriginType::Restaurant => 'restaurant_votes_count',
            OriginType::Unknown => null,
        };
    }
}
