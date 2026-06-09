<?php

namespace App\View\Components\Feed;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class PostCard extends Component
{
    public array $ratingVotingState = [];

    public bool $canDeletePost = false;

    public bool $canReportPost = false;

    public bool $canModeratePost = false;

    public bool $showNoActionsFallback = false;

    public function __construct(
        public Post $post,
        public bool $selected = false,
        array $ratingVotingState = [],
        bool $canDeletePost = false,
        bool $canReportPost = false,
        bool $canModeratePost = false,
    ) {
        $this->ratingVotingState = $ratingVotingState;
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
