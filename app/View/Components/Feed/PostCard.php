<?php

namespace App\View\Components\Feed;

use App\Models\Post;
use App\Models\RatingGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\Component;

final class PostCard extends Component
{
    public array $ratingVotingState = [];

    /** @var Collection<int, RatingGroup> */
    public Collection $ratingGroups;

    public bool $canDeletePost = false;

    public bool $canReportPost = false;

    public bool $canModeratePost = false;

    public bool $showNoActionsFallback = false;

    public function __construct(
        public Post $post,
        public bool $selected = false,
        array $ratingVotingState = [],
        ?Collection $ratingGroups = null,
        bool $canDeletePost = false,
        bool $canReportPost = false,
        bool $canModeratePost = false,
    ) {
        $this->ratingVotingState = $ratingVotingState;
        $this->ratingGroups = $ratingGroups ?? new Collection;
        $this->canDeletePost = $canDeletePost;
        $this->canReportPost = $canReportPost;
        $this->canModeratePost = $canModeratePost;
        $this->showNoActionsFallback = ! ($canReportPost || $canDeletePost || $canModeratePost);
    }

    public function render(): View
    {
        return view('components.feed.post-card');
    }
}
