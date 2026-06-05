<?php

namespace App\Livewire\Voting;

use App\Actions\Rating\VoteRatingOptionAction;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Post;
use App\Models\RatingVote;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Rating\RatingVoteDistribution;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RatingVoting extends Component
{
    protected string $viewName = 'livewire.voting.rating-voting';

    protected string $votedEventName = 'rating-voted';

    public ?Post $post = null;

    public string $groupKey;

    public string $error = '';

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

        $this->dispatch($this->votedEventName, postId: $this->post->id, groupKey: $this->groupKey);
    }

    public function render(
        RatingConfigurationManager $configuration,
        RatingVoteDistribution $voteDistribution,
    ): View {
        $group = $configuration->activeGroupByKey($this->groupKey);
        $selectedOptionId = null;
        $isOwnPost = $this->post !== null
            && auth()->check()
            && (int) $this->post->user_id === (int) auth()->id();
        $distribution = $group === null || $this->post === null
            ? []
            : $voteDistribution->forPostAndGroup($this->post, $group);

        if ($group !== null && $this->post !== null && auth()->check()) {
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
