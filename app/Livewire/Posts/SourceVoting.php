<?php

namespace App\Livewire\Posts;

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use Livewire\Attributes\On;

final class SourceVoting extends RatingVoting
{
    protected string $viewName = 'livewire.posts.source-voting';

    protected string $votedEventName = 'source-voted';

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

    #[On('source-voted')]
    public function refreshAfterSourceVote(int $postId): void
    {
        if ($postId === $this->postId) {
            $this->post = Post::query()->published()->find($postId);
            $this->hasPreloadedState = false;
        }
    }
}
