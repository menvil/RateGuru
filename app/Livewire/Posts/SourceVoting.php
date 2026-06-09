<?php

namespace App\Livewire\Posts;

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;

final class SourceVoting extends RatingVoting
{
    protected string $viewName = 'livewire.posts.source-voting';

    public int $postId;

    public function mount(
        int $postId,
        bool $hasPreloadedState = false,
        array $preloadedDistribution = [],
        ?int $preloadedSelectedOptionId = null,
    ): void {
        $this->postId = $postId;
        $this->post = Post::query()->published()->find($postId);
        $this->groupKey = 'source';
        $this->hasPreloadedState = $hasPreloadedState;
        $this->preloadedDistribution = $preloadedDistribution;
        $this->preloadedSelectedOptionId = $preloadedSelectedOptionId;
    }
}
