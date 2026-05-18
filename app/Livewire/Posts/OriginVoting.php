<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VoteOriginAction;
use App\Enums\OriginType;
use App\Exceptions\Votes\CannotVoteOriginException;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class OriginVoting extends Component
{
    public int $postId;

    public string $error = '';

    public function getPostProperty(): ?Post
    {
        return Post::query()
            ->published()
            ->find($this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.origin-voting', [
            'post' => $this->post,
        ]);
    }
}
