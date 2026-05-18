<?php

namespace App\Actions\Counters;

use App\Data\Counters\PostCounterSnapshot;
use App\Models\Post;

final class RecalculatePostCountersAction
{
    public function handle(Post $post): PostCounterSnapshot
    {
        throw new \LogicException('Not implemented yet.');
    }
}
