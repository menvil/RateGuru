<?php

namespace App\Support\Rating;

use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;

class RatingVoteDistribution
{
    /**
     * @return array<int, array{
     *     option: RatingOption,
     *     count: int,
     *     percent: float,
     *     label: string
     * }>
     */
    public function forPostAndGroup(Post $post, RatingGroup $group): array
    {
        $counts = RatingVote::query()
            ->where('post_id', $post->id)
            ->where('rating_group_id', $group->id)
            ->selectRaw('rating_option_id, COUNT(*) as aggregate')
            ->groupBy('rating_option_id')
            ->pluck('aggregate', 'rating_option_id');

        $total = (int) $counts->sum();

        return $group->options()
            ->ordered()
            ->get()
            ->mapWithKeys(function ($option) use ($counts, $total): array {
                $count = (int) ($counts[$option->id] ?? 0);

                return [
                    $option->id => [
                        'option' => $option,
                        'count' => $count,
                        'percent' => $percent = $total > 0
                            ? round(($count / $total) * 100, 1)
                            : 0.0,
                        'label' => $this->label($count, $percent),
                    ],
                ];
            })
            ->all();
    }

    public function label(int $count, float $percent): string
    {
        return $count.' '.($count === 1 ? 'vote' : 'votes').' · '.number_format($percent, 0).'%';
    }
}
