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

        return $this->saveWithCounts($post);
    }

    /**
     * The post's score from the counts it holds — counts a caller holding the
     * post's lock has just filled in among them — saved with them in one
     * update. A second update of a post in one transaction would have
     * PostgreSQL check the post's foreign keys, locking its author's `users`
     * row after the post, out of the lock order
     * (docs/architecture/user-lifecycle.md).
     */
    public function saveWithCounts(Post $post): float
    {
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
