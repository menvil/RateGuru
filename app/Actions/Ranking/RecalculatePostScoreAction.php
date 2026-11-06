<?php

namespace App\Actions\Ranking;

use App\Models\Post;
use App\Support\Ranking\HotScoreCalculator;
use RuntimeException;

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

        // A save a model event cancels returns false rather than throwing; a
        // score not stored is not one to report, and inside a caller's
        // transaction it takes the whole of it back.
        if (! $post->forceFill(['hot_score' => $score])->save()) {
            throw new RuntimeException('The post\'s hot score was not saved.');
        }

        return $score;
    }
}
