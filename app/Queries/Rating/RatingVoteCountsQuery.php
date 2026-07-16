<?php

namespace App\Queries\Rating;

use App\Models\RatingVote;
use Illuminate\Support\Collection;

final class RatingVoteCountsQuery
{
    /**
     * @param  list<int>  $postIds
     * @param  list<int>  $groupIds
     * @return Collection<int|string, mixed>
     */
    public function forPostsAndGroups(array $postIds, array $groupIds): Collection
    {
        if ($postIds === [] || $groupIds === []) {
            return collect();
        }

        $rows = RatingVote::query()
            ->whereIn('post_id', $postIds)
            ->whereIn('rating_group_id', $groupIds)
            ->select(['post_id', 'rating_group_id', 'rating_option_id'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('post_id', 'rating_group_id', 'rating_option_id')
            ->get();

        return collect($rows->all())->groupBy(['post_id', 'rating_group_id']);
    }
}
