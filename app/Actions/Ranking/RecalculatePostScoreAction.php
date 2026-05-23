<?php

namespace App\Actions\Ranking;

use App\Models\Post;
use App\Support\Ranking\HotScoreCalculator;

final class RecalculatePostScoreAction
{
    public function __construct(
        private readonly HotScoreCalculator $calculator,
    ) {}

    public function handle(Post $post): float
    {
        $post->refresh();

        $score = $this->calculator->calculate(
            upvotes: (int) $post->upvotes_count,
            downvotes: (int) $post->downvotes_count,
            commentsCount: (int) $post->comments_count,
            createdAt: $post->created_at,
            now: now(),
        );

        $post->forceFill([
            'hot_score' => $score,
        ])->save();

        return $score;
    }
}
