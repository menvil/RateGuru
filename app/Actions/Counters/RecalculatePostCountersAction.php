<?php

namespace App\Actions\Counters;

use App\Data\Counters\PostCounterSnapshot;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\VoteType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
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

        $downvotes = PostVote::query()
            ->where('post_id', $post->id)
            ->where('type', VoteType::Down)
            ->count();

        $homemadeVotes = OriginVote::query()
            ->where('post_id', $post->id)
            ->where('origin', OriginType::Homemade)
            ->count();

        $restaurantVotes = OriginVote::query()
            ->where('post_id', $post->id)
            ->where('origin', OriginType::Restaurant)
            ->count();

        $post->forceFill([
            'upvotes_count' => $upvotes,
            'downvotes_count' => $downvotes,
            'homemade_votes_count' => $homemadeVotes,
            'restaurant_votes_count' => $restaurantVotes,
        ])->save();

        $cuisineCounts = CuisineVote::query()
            ->where('post_id', $post->id)
            ->selectRaw('cuisine, COUNT(*) as total')
            ->groupBy('cuisine')
            ->pluck('total', 'cuisine')
            ->all();

        $cuisineVotes = [];

        foreach (CuisineType::votable() as $cuisine) {
            $cuisineVotes[$cuisine->value] = (int) ($cuisineCounts[$cuisine->value] ?? 0);
        }

        return new PostCounterSnapshot(
            upvotes: $upvotes,
            downvotes: $downvotes,
            homemadeVotes: $homemadeVotes,
            restaurantVotes: $restaurantVotes,
            cuisineVotes: $cuisineVotes,
        );
    }
}
