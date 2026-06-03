<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
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

        $currentVote = $this->currentVoteFor($post);

        if ($currentVote === $voteType) {
            return;
        }

        $voteToApply = $voteType;

        try {
            $votePostAction->handle(auth()->user(), $post, $voteToApply);
        } catch (CannotVoteException $e) {
            $this->error = $e->getMessage();
            $this->dispatch('post-vote-error', postId: $this->postId, message: $this->error);

            return;
        }

        unset($this->post);

        $this->dispatch('post-voted', postId: $this->postId);
    }

    #[On('post-voted')]
    public function refreshAfterPostVote(int $postId): void
    {
        if ($postId === $this->postId) {
            unset($this->post);
        }
    }

    public function render(): View
    {
        $post = $this->post;
        $currentVote = null;

        if ($post !== null) {
            $currentVote = $this->currentVoteFor($post)?->value;
        }

        return view('livewire.posts.post-voting', [
            'post' => $post,
            'currentVote' => $currentVote,
            'isOwnPost' => $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id(),
            'upActive' => $currentVote === VoteType::Up->value,
            'downActive' => $currentVote === VoteType::Down->value,
            'votingDisabled' => false,
            'score' => (int) ($post?->score ?? 0),
        ]);
    }

    private function currentVoteFor(Post $post): ?VoteType
    {
        if (! auth()->check()) {
            return null;
        }

        return $post->postVotes()
            ->where('user_id', auth()->id())
            ->first()
            ?->type;
    }
}
