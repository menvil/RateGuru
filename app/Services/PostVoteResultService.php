<?php

namespace App\Services;

use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;

final class PostVoteResultService
{
    /**
     * @return array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int,total:int,current:?string}
     */
    public function originDistribution(Post $post, ?User $user): array
    {
        $homemade = (int) ($post->homemade_votes_count ?? 0);
        $restaurant = (int) ($post->restaurant_votes_count ?? 0);
        $total = $homemade + $restaurant;
        $current = $user
            ? $post->originVotes()->where('user_id', $user->id)->first()?->origin?->value
            : null;

        if ($current === null) {
            return [
                'homemade' => 0,
                'restaurant' => 0,
                'homemadePct' => 0,
                'restaurantPct' => 0,
                'total' => 0,
                'current' => null,
            ];
        }

        $homemadePct = $total > 0 ? (int) round(($homemade / $total) * 100) : 0;

        return [
            'homemade' => $homemade,
            'restaurant' => $restaurant,
            'homemadePct' => $homemadePct,
            'restaurantPct' => $total > 0 ? 100 - $homemadePct : 0,
            'total' => $total,
            'current' => $current,
        ];
    }

    /**
     * @return array{rows:list<array{label:string,count:int,percentage:int}>,total:int,current:?string}
     */
    public function cuisineDistribution(Post $post, ?User $user): array
    {
        $current = $user
            ? $post->cuisineVotes()->where('user_id', $user->id)->first()?->cuisine?->value
            : null;

        $counts = $current === null
            ? collect()
            : CuisineVote::query()
                ->where('post_id', $post->id)
                ->selectRaw('cuisine, COUNT(*) as total')
                ->groupBy('cuisine')
                ->pluck('total', 'cuisine');

        $total = (int) $counts->sum();

        $rows = collect(CuisineType::votable())
            ->map(function (CuisineType $cuisine) use ($counts, $total): array {
                $count = (int) ($counts[$cuisine->value] ?? 0);

                return [
                    'label' => match ($cuisine) {
                        CuisineType::Italian => 'IT',
                        CuisineType::Asian => 'AS',
                        CuisineType::American => 'US',
                        CuisineType::Mexican => 'MX',
                        CuisineType::Other => 'OT',
                        CuisineType::Unknown => 'UN',
                    },
                    'count' => $count,
                    'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                ];
            })
            ->all();

        return ['rows' => $rows, 'total' => $total, 'current' => $current];
    }
}
