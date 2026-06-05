<?php

namespace App\Livewire\Voting;

use App\Actions\Rating\VoteRatingOptionAction;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Post;
use App\Models\RatingVote;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RatingVoting extends Component
{
    public Post $post;

    public string $groupKey;

    public string $error = '';

    public function vote(
        int $optionId,
        RatingConfigurationManager $configuration,
        VoteRatingOptionAction $voteRatingOption,
    ): void {
        $this->error = '';
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

        $this->dispatch('rating-voted', postId: $this->post->id, groupKey: $this->groupKey);
    }

    public function render(RatingConfigurationManager $configuration): View
    {
        $group = $configuration->activeGroupByKey($this->groupKey);
        $selectedOptionId = null;
        $isOwnPost = auth()->check() && (int) $this->post->user_id === (int) auth()->id();

        if ($group !== null && auth()->check()) {
            $selectedOptionId = RatingVote::query()
                ->where('user_id', auth()->id())
                ->where('post_id', $this->post->id)
                ->where('rating_group_id', $group->id)
                ->value('rating_option_id');
        }

        return view('livewire.voting.rating-voting', [
            'group' => $group,
            'isOwnPost' => $isOwnPost,
            'selectedOptionId' => $selectedOptionId,
            'votingDisabled' => ! auth()->check() || $isOwnPost,
        ]);
    }
}
