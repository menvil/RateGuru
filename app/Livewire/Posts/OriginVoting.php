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

    public function vote(string $origin, VoteOriginAction $voteOriginAction): void
    {
        $this->error = '';

        $originType = OriginType::tryFrom($origin);

        if ($originType === null) {
            return;
        }

        $post = $this->post;

        if ($post === null) {
            $this->error = 'This post is no longer available.';

            return;
        }

        try {
            $voteOriginAction->handle(auth()->user(), $post, $originType);
        } catch (CannotVoteOriginException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->post);

        $this->dispatch('origin-voted', postId: $this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.origin-voting', [
            'post' => $this->post,
        ]);
    }
}
