<?php

namespace App\Support\Rating;

use App\Models\Post;
use App\Models\RatingVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class RatingVotingStateLoader
{
    public function __construct(
        private readonly RatingConfigurationManager $configuration,
        private readonly RatingVoteDistribution $distribution,
    ) {}

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<int, array<string, array{
     *     distribution: array<int, array{count: int, percent: float, label: string}>,
     *     selected_option_id: int|null
     * }>>
     */
    public function forPosts(Collection $posts, ?User $user): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $groups = $this->configuration->activeGroups();
        $postIds = $posts->modelKeys();
        $groupIds = $groups->modelKeys();
        $counts = RatingVote::query()
            ->whereIn('post_id', $postIds)
            ->whereIn('rating_group_id', $groupIds)
            ->selectRaw('post_id, rating_group_id, rating_option_id, COUNT(*) as aggregate')
            ->groupBy('post_id', 'rating_group_id', 'rating_option_id')
            ->get()
            ->groupBy(['post_id', 'rating_group_id']);
        $selected = $user === null
            ? collect()
            : RatingVote::query()
                ->where('user_id', $user->id)
                ->whereIn('post_id', $postIds)
                ->whereIn('rating_group_id', $groupIds)
                ->get()
                ->keyBy(fn (RatingVote $vote): string => "{$vote->post_id}:{$vote->rating_group_id}");
        $states = [];

        foreach ($posts as $post) {
            foreach ($groups as $group) {
                $groupCounts = collect($counts[$post->id][$group->id] ?? [])
                    ->pluck('aggregate', 'rating_option_id');
                $total = (int) $groupCounts->sum();
                $distribution = [];

                foreach ($group->options as $option) {
                    $count = (int) ($groupCounts[$option->id] ?? 0);
                    $percent = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
                    $distribution[$option->id] = [
                        'count' => $count,
                        'percent' => $percent,
                        'label' => $this->distribution->label($count, $percent),
                    ];
                }

                $states[$post->id][$group->key] = [
                    'distribution' => $distribution,
                    'selected_option_id' => $selected->get("{$post->id}:{$group->id}")?->rating_option_id,
                ];
            }
        }

        return $states;
    }
}
