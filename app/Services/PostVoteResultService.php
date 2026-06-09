<?php

namespace App\Services;

use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

final class PostVoteResultService
{
    /**
     * @return array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int,total:int,current:?string}
     */
    public function originDistribution(Post $post, ?User $user): array
    {
        $homemade = (int) ($post->homemade_votes_count ?? 0);
        $restaurant = (int) ($post->restaurant_votes_count ?? 0);
        $current = $user
            ? $post->originVotes()->where('user_id', $user->id)->latest('id')->first()?->origin?->value
            : null;

        return $this->buildOriginDistribution($homemade, $restaurant, $current);
    }

    /**
     * @param  iterable<Post>  $posts
     * @return array<int,array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int,total:int,current:?string}>
     */
    public function originDistributions(iterable $posts, ?User $user): array
    {
        $posts = collect($posts);
        $postIds = $posts->pluck('id')->filter()->values()->all();
        $currentByPost = $this->currentOriginsByPost($postIds, $user);

        return $posts
            ->mapWithKeys(fn (Post $post): array => [
                (int) $post->id => $this->buildOriginDistribution(
                    homemade: (int) ($post->homemade_votes_count ?? 0),
                    restaurant: (int) ($post->restaurant_votes_count ?? 0),
                    current: $currentByPost[(int) $post->id] ?? null,
                ),
            ])
            ->all();
    }

    /**
     * @return array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int,total:int,current:?string}
     */
    private function buildOriginDistribution(int $homemade, int $restaurant, ?string $current): array
    {
        $total = $homemade + $restaurant;
        $homemadePct = $total > 0 ? (int) round(($homemade / $total) * 100) : 0;

        return [
            'homemade'      => $homemade,
            'restaurant'    => $restaurant,
            'homemadePct'   => $homemadePct,
            'restaurantPct' => $total > 0 ? 100 - $homemadePct : 0,
            'total'         => $total,
            'current'       => $current,
        ];
    }

    /**
     * @return array{rows:list<array{label:string,count:int,percentage:int}>,total:int,current:?string}
     */
    public function cuisineDistribution(Post $post, ?User $user): array
    {
        $current = $user
            ? $post->cuisineVotes()->where('user_id', $user->id)->latest('id')->first()?->cuisine?->value
            : null;

        $counts = CuisineVote::query()
            ->where('post_id', $post->id)
            ->selectRaw('cuisine, COUNT(*) as total')
            ->groupBy('cuisine')
            ->pluck('total', 'cuisine');

        return $this->buildCuisineDistribution($counts, $current);
    }

    /**
     * @param  iterable<Post>  $posts
     * @return array<int,array{rows:list<array{label:string,count:int,percentage:int}>,total:int,current:?string}>
     */
    public function cuisineDistributions(iterable $posts, ?User $user): array
    {
        $posts = collect($posts);
        $postIds = $posts->pluck('id')->filter()->values()->all();
        $currentByPost = $this->currentCuisinesByPost($postIds, $user);
        $countsByPost = $this->cuisineCountsByPost($postIds);

        return $posts
            ->mapWithKeys(fn (Post $post): array => [
                (int) $post->id => $this->buildCuisineDistribution(
                    counts: collect($countsByPost[(int) $post->id] ?? []),
                    current: $currentByPost[(int) $post->id] ?? null,
                ),
            ])
            ->all();
    }

    /**
     * @param  Collection<string,int>  $counts
     * @return array{rows:list<array{label:string,count:int,percentage:int}>,total:int,current:?string}
     */
    private function buildCuisineDistribution(Collection $counts, ?string $current): array
    {
        $total = (int) $counts->sum();

        $rows = collect(CuisineType::votable())
            ->map(function (CuisineType $cuisine) use ($counts, $total): array {
                $count = (int) ($counts[$cuisine->value] ?? 0);

                return [
                    'label' => $cuisine->shortLabel(),
                    'count' => $count,
                    'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                ];
            })
            ->all();

        return ['rows' => $rows, 'total' => $total, 'current' => $current];
    }

    /**
     * @param  array<int>  $postIds
     * @return array<int,string>
     */
    private function currentOriginsByPost(array $postIds, ?User $user): array
    {
        if ($user === null || $postIds === []) {
            return [];
        }

        return OriginVote::query()
            ->where('user_id', $user->id)
            ->whereIn('post_id', $postIds)
            ->latest('id')
            ->get()
            ->unique('post_id')
            ->mapWithKeys(fn (OriginVote $vote): array => [(int) $vote->post_id => $vote->origin?->value])
            ->filter()
            ->all();
    }

    /**
     * @param  array<int>  $postIds
     * @return array<int,string>
     */
    private function currentCuisinesByPost(array $postIds, ?User $user): array
    {
        if ($user === null || $postIds === []) {
            return [];
        }

        return CuisineVote::query()
            ->where('user_id', $user->id)
            ->whereIn('post_id', $postIds)
            ->latest('id')
            ->get()
            ->unique('post_id')
            ->mapWithKeys(fn (CuisineVote $vote): array => [(int) $vote->post_id => $vote->cuisine?->value])
            ->filter()
            ->all();
    }

    /**
     * @param  array<int>  $postIds
     * @return array<int,array<string,int>>
     */
    private function cuisineCountsByPost(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        return CuisineVote::query()
            ->whereIn('post_id', $postIds)
            ->selectRaw('post_id, cuisine, COUNT(*) as total')
            ->groupBy('post_id', 'cuisine')
            ->get()
            ->groupBy('post_id')
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn (CuisineVote $vote): array => [$vote->cuisine->value => (int) $vote->total])
                ->all())
            ->all();
    }
}
