<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Livewire\Concerns\OpensAuthModal;
use App\Models\Post;
use App\Support\Observability\GuestVoteAttemptLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** @property-read Post|null $post */
final class PostVoting extends Component
{
    use OpensAuthModal;

    public int $postId;

    public string $variant = 'buttons';

    public string $error = '';

    /**
     * What the list that drew this card already read for the whole page —
     * the counts, the viewer's vote, whether they may vote — so the first
     * render asks nothing of its own. Dropped once anything here changes.
     */
    #[Locked]
    public bool $hasPreloadedState = false;

    #[Locked]
    public int $preloadedUpvotes = 0;

    #[Locked]
    public int $preloadedDownvotes = 0;

    #[Locked]
    public ?string $preloadedVote = null;

    #[Locked]
    public bool $preloadedCanVote = false;

    public function getPostProperty(): ?Post
    {
        return Post::query()
            ->published()
            ->find($this->postId);
    }

    public function vote(string $type, VotePostAction $votePostAction, GuestVoteAttemptLog $guestVoteAttempts): void
    {
        $this->error = '';
        // From here on the post as it is: a refusal below — the post gone, a vote
        // no longer allowed — is drawn beside the buttons it now has.
        $this->hasPreloadedState = false;

        $voteType = VoteType::tryFrom($type);

        if ($voteType === null) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            $guestVoteAttempts->record('post', ['post_id' => $this->postId, 'vote' => $voteType->value]);
            $this->error = __('ui.voting.sign_in_to_vote');
            $this->dispatch('post-vote-error', postId: $this->postId, message: $this->error);
            $this->openAuthModal();

            return;
        }

        $post = $this->post;

        if ($post === null) {
            $this->error = __('ui.post.unavailable');

            return;
        }

        if (! $user->can('vote', $post)) {
            return;
        }

        $currentVote = $this->currentVoteFor($post);

        if ($currentVote === $voteType) {
            return;
        }

        $voteToApply = $voteType;

        try {
            $votePostAction->handle($user, $post, $voteToApply);
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
            $this->hasPreloadedState = false;
        }
    }

    public function render(): View
    {
        if ($this->hasPreloadedState) {
            return $this->voting(true, $this->preloadedUpvotes, $this->preloadedDownvotes, $this->preloadedVote, $this->preloadedCanVote);
        }

        $post = $this->post;
        $user = auth()->user();

        return $this->voting(
            $post !== null,
            (int) $post?->upvotes_count,
            (int) $post?->downvotes_count,
            $post !== null ? $this->currentVoteFor($post)?->value : null,
            $post !== null && ($user === null || $user->can('vote', $post)),
        );
    }

    private function voting(bool $available, int $upvotes, int $downvotes, ?string $currentVote, bool $canVote): View
    {
        return view('livewire.posts.post-voting', [
            'available' => $available,
            'upvotes' => $upvotes,
            'downvotes' => $downvotes,
            'currentVote' => $currentVote,
            'upActive' => $currentVote === VoteType::Up->value,
            'downActive' => $currentVote === VoteType::Down->value,
            'votingDisabled' => ! $canVote,
            'score' => $upvotes - $downvotes,
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
