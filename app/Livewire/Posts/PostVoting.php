<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PostVoting extends Component
{
    public int $postId;

    public string $error = '';

    public function getPostProperty(): Post
    {
        return Post::query()
            ->published()
            ->findOrFail($this->postId);
    }

    public function vote(string $type, VotePostAction $votePostAction): void
    {
        $this->error = '';

        try {
            $votePostAction->handle(auth()->user(), $this->post, VoteType::from($type));
        } catch (CannotVoteException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->post);

        $this->dispatch('post-voted', postId: $this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.post-voting', [
            'post' => $this->post,
        ]);
    }
}
