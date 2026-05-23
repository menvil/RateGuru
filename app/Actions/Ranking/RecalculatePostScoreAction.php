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
        throw new \LogicException('Not implemented yet.');
    }
}
