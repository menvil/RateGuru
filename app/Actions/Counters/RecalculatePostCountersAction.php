<?php

namespace App\Actions\Counters;

use App\Data\Counters\PostCounterSnapshot;
use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;

final class RecalculatePostCountersAction
{
    public function handle(Post $post): PostCounterSnapshot
    {
        $upvotes = PostVote::query()
            ->where('post_id', $post->id)
            ->where('type', VoteType::Up)
            ->count();

        $post->forceFill([
            'upvotes_count' => $upvotes,
        ])->save();

        $fresh = $post->fresh();

        return new PostCounterSnapshot(
            upvotes: $upvotes,
            downvotes: (int) $fresh->downvotes_count,
            homemadeVotes: (int) $fresh->homemade_votes_count,
            restaurantVotes: (int) $fresh->restaurant_votes_count,
            cuisineVotes: [],
        );
    }
}
