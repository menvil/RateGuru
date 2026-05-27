<?php

namespace App\View\Components\Feed;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class PostCard extends Component
{
    public ?array $originDistribution = null;

    public ?array $cuisineDistribution = null;

    public bool $canDeletePost = false;

    public bool $canReportPost = false;

    public bool $canModeratePost = false;

    public bool $showNoActionsFallback = false;

    public function __construct(
        public Post $post,
        public bool $selected = false,
        ?array $originDistribution = null,
        ?array $cuisineDistribution = null,
        bool $canDeletePost = false,
        bool $canReportPost = false,
        bool $canModeratePost = false,
    ) {
        $this->originDistribution = $originDistribution;
        $this->cuisineDistribution = $cuisineDistribution;
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
