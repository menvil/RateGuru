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

    /**
     * @return array{homemade:int,restaurant:int,homemadePct:int,restaurantPct:int}
     */
    public function getOriginDistributionProperty(): array
    {
        $post = $this->post;

        $homemade = (int) ($post?->homemade_votes_count ?? 0);
        $restaurant = (int) ($post?->restaurant_votes_count ?? 0);
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
        $post = $this->post;
        $currentOrigin = null;

        if ($post !== null && auth()->check()) {
            $currentOrigin = $post->originVotes()
                ->where('user_id', auth()->id())
                ->latest('id')
                ->first()
                ?->origin
                ?->value;
        }

        return view('livewire.posts.origin-voting', [
            'post' => $post,
            'currentOrigin' => $currentOrigin,
            'isOwnPost' => $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id(),
            'hasVoted' => $currentOrigin !== null,
            'votingDisabled' => $currentOrigin !== null || ($post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id()),
            'showOwnPostVoteError' => $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id() && $currentOrigin === null,
        ]);
    }
}
