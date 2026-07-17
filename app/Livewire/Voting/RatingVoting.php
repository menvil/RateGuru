<?php

namespace App\Livewire\Voting;

use App\Actions\Rating\VoteRatingOptionAction;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Post;
use App\Models\RatingVote;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Rating\RatingVoteDistribution;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class RatingVoting extends Component
{
    protected string $viewName = 'livewire.voting.rating-voting';

    protected string $votedEventName = 'rating-voted';

    public ?Post $post = null;

    public string $groupKey;

    public string $error = '';

    public string $variant = 'default';

    public bool $hasPreloadedState = false;

    public array $preloadedDistribution = [];

    public ?int $preloadedSelectedOptionId = null;

    /**
     * Re-sync this component whenever any rating vote happens for the same
     * post + group, so results stay in sync across the feed card and the
     * open post drawer / page without reloading the whole card.
     */
    #[On('rating-voted')]
    public function onRatingVoted(int $postId, string $groupKey): void
    {
        $currentPostId = $this->post instanceof Post ? (int) $this->post->id : 0;

        if ($currentPostId === $postId && $this->groupKey === $groupKey) {
            $this->hasPreloadedState = false;
            $this->post = Post::query()->published()->find($postId);
        }
    }

    public function vote(
        int $optionId,
        RatingConfigurationManager $configuration,
        VoteRatingOptionAction $voteRatingOption,
    ): void {
        $this->error = '';

        if ($this->post === null) {
            $this->error = 'This post is no longer available.';

            return;
        }

        if (auth()->check() && (int) $this->post->user_id === (int) auth()->id()) {
            return;
        }

        $group = $configuration->activeGroupByKey($this->groupKey);
        $option = $group?->options->firstWhere('id', $optionId);

        if ($option === null) {
            $this->error = 'Rating option is not available for this group.';

            return;
        }

        try {
            $voteRatingOption->handle(auth()->user(), $this->post, $option);
        } catch (CannotVoteForRatingOptionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->hasPreloadedState = false;
        $this->dispatch($this->votedEventName, postId: $this->post->id, groupKey: $this->groupKey);
    }

    public function render(
        RatingConfigurationManager $configuration,
        RatingVoteDistribution $voteDistribution,
    ): View {
        $group = $configuration->activeGroupByKey($this->groupKey);
        $selectedOptionId = $this->hasPreloadedState ? $this->preloadedSelectedOptionId : null;
        $isOwnPost = $this->post !== null
            && auth()->check()
            && (int) $this->post->user_id === (int) auth()->id();
        $distribution = $this->hasPreloadedState
            ? $this->preloadedDistribution
            : ($group === null || $this->post === null
            ? []
            : $voteDistribution->forPostAndGroup($this->post, $group));

        if (! $this->hasPreloadedState && $group !== null && $this->post !== null && auth()->check()) {
            $selectedOptionId = RatingVote::query()
                ->where('user_id', auth()->id())
                ->where('post_id', $this->post->id)
                ->where('rating_group_id', $group->id)
                ->value('rating_option_id');
        }

        return view($this->viewName, [
            'distribution' => $distribution,
            'group' => $group,
            'isOwnPost' => $isOwnPost,
            'selectedOptionId' => $selectedOptionId,
            'votingDisabled' => $this->post === null || ! auth()->check() || $isOwnPost,
        ]);
    }
}
