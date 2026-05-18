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
