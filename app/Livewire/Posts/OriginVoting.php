<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VoteOriginAction;
use App\Enums\OriginType;
use App\Exceptions\Votes\CannotVoteOriginException;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @property-read Post|null $post
 *
 * @deprecated Use SourceVoting for new UI code until Phase 44 replaces legacy source storage.
 */
class OriginVoting extends Component
{
    protected string $viewName = 'livewire.posts.origin-voting';

    protected string $votedEventName = 'origin-voted';

    public int $postId;

    public string $error = '';

    public function getPostProperty(): ?Post
    {
        return Post::query()
            ->published()
            ->find($this->postId);
    }

    /**
     * @return array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int}
     */
    public function getOriginDistributionProperty(): array
    {
        $post = $this->post;

        $homemade = (int) $post?->homemade_votes_count;
        $restaurant = (int) $post?->restaurant_votes_count;
        $total = $homemade + $restaurant;

        $homemadePct = $total > 0 ? (int) round(($homemade / $total) * 100) : 0;
        $restaurantPct = $total > 0 ? 100 - $homemadePct : 0;

        return [
            'homemade' => $homemade,
            'restaurant' => $restaurant,
            'homemadePct' => $homemadePct,
            'restaurantPct' => $restaurantPct,
        ];
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

        if (auth()->check() && (int) $post->user_id === (int) auth()->id()) {
            return;
        }

        try {
            $voteOriginAction->handle(auth()->user(), $post, $originType);
        } catch (CannotVoteOriginException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->post);

        $this->dispatch($this->votedEventName, postId: $this->postId);
    }

    #[On('origin-voted')]
    public function refreshAfterOriginVote(int $postId): void
    {
        $this->refreshMatchingPost($postId);
    }

    #[On('source-voted')]
    public function refreshAfterSourceVote(int $postId): void
    {
        $this->refreshMatchingPost($postId);
    }

    public function render(): View
    {
        $post = $this->post;
        $currentValue = $this->resolveCurrentOrigin($post);
        $isOwnPost = $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id();

        return view($this->viewName, [
            'post' => $post,
            'currentValue' => $currentValue,
            'isOwnPost' => $isOwnPost,
            'hasVoted' => $currentValue !== null,
            'votingDisabled' => ! auth()->check() || $isOwnPost || $currentValue !== null,
        ]);
    }

    protected function resolveCurrentOrigin(?Post $post): ?string
    {
        if ($post === null || ! auth()->check()) {
            return null;
        }

        return $post->originVotes()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->first()
            ?->origin
            ?->value;
    }

    private function refreshMatchingPost(int $postId): void
    {
        if ($postId === $this->postId) {
            unset($this->post);
        }
    }
}
