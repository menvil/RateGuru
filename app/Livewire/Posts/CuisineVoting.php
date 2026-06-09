<?php

namespace App\Livewire\Posts;

use App\Actions\Votes\VoteCuisineAction;
use App\Enums\CuisineType;
use App\Exceptions\Votes\CannotVoteCuisineException;
use App\Models\CuisineVote;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @deprecated Use CategoryVoting for new UI code until Phase 44 replaces legacy category storage.
 */
class CuisineVoting extends Component
{
    protected string $viewName = 'livewire.posts.cuisine-voting';

    protected string $votedEventName = 'cuisine-voted';

    public int $postId;

    public string $variant = 'default';

    public string $error = '';

    /**
     * @return list<CuisineType>
     */
    protected function options(): array
    {
        return CuisineType::votable();
    }

    public function labelFor(CuisineType $cuisine): string
    {
        return $cuisine->label();
    }

    public function shortLabelFor(CuisineType $cuisine): string
    {
        return $cuisine->shortLabel();
    }

    public function getPostProperty(): ?Post
    {
        return Post::query()
            ->published()
            ->find($this->postId);
    }

    public function vote(string $cuisine, VoteCuisineAction $voteCuisineAction): void
    {
        $this->error = '';

        $cuisineType = CuisineType::tryFrom($cuisine);

        if ($cuisineType === null) {
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
            $voteCuisineAction->handle(auth()->user(), $post, $cuisineType);
        } catch (CannotVoteCuisineException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // Drop the memoized distribution so the panel reflects the new
        // vote on the re-render that follows this request.
        unset($this->distribution);

        $this->dispatch($this->votedEventName, postId: $this->postId);
    }

    /**
     * Forces a re-render (and fresh distribution) when another instance of
     * this component reports a cuisine vote for the same post.
     */
    #[On('cuisine-voted')]
    public function refreshAfterCuisineVote(int $postId): void
    {
        if ($postId === $this->postId) {
            unset($this->distribution);
        }
    }

    #[On('category-voted')]
    public function refreshAfterCategoryVote(int $postId): void
    {
        $this->refreshAfterCuisineVote($postId);
    }

    /**
     * Distribution is aggregated from cuisine_votes — there are no
     * persisted cuisine counter columns on posts (Phase 15 constraint).
     *
     * @return array{rows:list<array{cuisine:CuisineType,label:string,count:int,percentage:int}>,total:int}
     */
    public function getDistributionProperty(): array
    {
        $counts = CuisineVote::query()
            ->where('post_id', $this->postId)
            ->selectRaw('cuisine, COUNT(*) as total')
            ->groupBy('cuisine')
            ->pluck('total', 'cuisine');

        $total = (int) $counts->sum();

        $rows = collect($this->options())
            ->map(function (CuisineType $cuisine) use ($counts, $total) {
                $count = (int) ($counts[$cuisine->value] ?? 0);

                return [
                    'cuisine' => $cuisine,
                    'label' => $this->labelFor($cuisine),
                    'count' => $count,
                    'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                ];
            })
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function render(): View
    {
        $post = $this->post;
        $currentValue = $this->resolveCurrentCuisine($post);
        $isOwnPost = $post !== null && auth()->check() && (int) $post->user_id === (int) auth()->id();

        return view($this->viewName, [
            'post' => $post,
            'options' => $this->options(),
            'currentValue' => $currentValue,
            'isOwnPost' => $isOwnPost,
            'hasVoted' => $currentValue !== null,
            'votingDisabled' => ! auth()->check() || $isOwnPost || $currentValue !== null,
        ]);
    }

    protected function resolveCurrentCuisine(?Post $post): ?string
    {
        if ($post === null || ! auth()->check()) {
            return null;
        }

        return $post->cuisineVotes()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->first()
            ?->cuisine
            ?->value;
    }
}
