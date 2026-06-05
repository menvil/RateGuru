<?php

namespace App\Livewire\Posts;

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use Livewire\Attributes\On;

final class CategoryVoting extends RatingVoting
{
    protected string $viewName = 'livewire.posts.category-voting';

    protected string $votedEventName = 'category-voted';

    public int $postId;

    public string $variant = 'default';

    public function mount(int $postId): void
    {
        $this->postId = $postId;
        $this->post = Post::query()->published()->find($postId);
        $this->groupKey = 'category';
    }

    #[On('category-voted')]
    public function refreshAfterCategoryVote(int $postId): void
    {
        if ($postId === $this->postId) {
            $this->post = Post::query()->published()->find($postId);
        }
    }
}
