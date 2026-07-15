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
        $options = $group->options()
            ->ordered()
            ->get();

        $counts = $options
            ->mapWithKeys(fn (RatingOption $option): array => [
                $option->id => RatingVote::query()
                    ->where('post_id', $post->id)
                    ->where('rating_group_id', $group->id)
                    ->where('rating_option_id', $option->id)
                    ->count(),
            ]);

        $total = (int) $counts->sum();

        return $options
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
