<?php

namespace App\Livewire\Voting;

use App\Models\Post;
use App\Models\RatingVote;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RatingVoting extends Component
{
    public Post $post;

    public string $groupKey;

    public function render(RatingConfigurationManager $configuration): View
    {
        $group = $configuration->activeGroupByKey($this->groupKey);
        $selectedOptionId = null;

        if ($group !== null && auth()->check()) {
            $selectedOptionId = RatingVote::query()
                ->where('user_id', auth()->id())
                ->where('post_id', $this->post->id)
                ->where('rating_group_id', $group->id)
                ->value('rating_option_id');
        }

        return view('livewire.voting.rating-voting', [
            'group' => $group,
            'selectedOptionId' => $selectedOptionId,
        ]);
    }
}
