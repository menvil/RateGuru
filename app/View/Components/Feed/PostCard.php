<?php

namespace App\View\Components\Feed;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class PostCard extends Component
{
    public ?array $originDistribution = null;

    public ?array $cuisineDistribution = null;

    public array $ratingVotingState = [];

    public bool $canDeletePost = false;

    public bool $canReportPost = false;

    public bool $canModeratePost = false;

    public bool $showNoActionsFallback = false;

    public bool $showOriginResults = false;

    public bool $showCuisineResults = false;

    public function __construct(
        public Post $post,
        public bool $selected = false,
        ?array $originDistribution = null,
        ?array $cuisineDistribution = null,
        array $ratingVotingState = [],
        bool $canDeletePost = false,
        bool $canReportPost = false,
        bool $canModeratePost = false,
    ) {
        $this->originDistribution = $originDistribution;
        $this->cuisineDistribution = $cuisineDistribution;
        $this->ratingVotingState = $ratingVotingState;
        $this->canDeletePost = $canDeletePost;
        $this->canReportPost = $canReportPost;
        $this->canModeratePost = $canModeratePost;
        $this->showNoActionsFallback = ! ($canReportPost || $canDeletePost || $canModeratePost);
        $this->showOriginResults = $this->shouldShowDistribution($originDistribution);
        $this->showCuisineResults = $this->shouldShowDistribution($cuisineDistribution);
    }

    public function render(): View
    {
        return view('components.feed.post-card');
    }

    private function shouldShowDistribution(?array $distribution): bool
    {
        return filled($distribution['current'] ?? null)
            && (int) ($distribution['total'] ?? 0) > 0;
    }
}
