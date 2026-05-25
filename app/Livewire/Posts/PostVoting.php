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

    public string $variant = 'buttons';

    public string $error = '';

    public function getPostProperty(): ?Post
    {
        return Post::query()
            ->published()
            ->find($this->postId);
    }

    public function vote(string $type, VotePostAction $votePostAction): void
    {
        $this->error = '';

        $voteType = VoteType::tryFrom($type);

        if ($voteType === null) {
            return;
        }

        $post = $this->post;

        if ($post === null) {
            $this->error = 'This post is no longer available.';

            return;
        }

        try {
            $votePostAction->handle(auth()->user(), $post, $voteType);
        } catch (CannotVoteException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->post);

        $this->dispatch('post-voted', postId: $this->postId);
    }

    public function render(): View
    {
        $post = $this->post;
        $currentVote = null;

        if ($post !== null && auth()->check()) {
            $currentVote = $post->postVotes()
                ->where('user_id', auth()->id())
                ->first()
                ?->type
                ?->value;
        }

        return view('livewire.posts.post-voting', [
            'post' => $post,
            'currentVote' => $currentVote,
        ]);
    }
}
