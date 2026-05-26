<?php

namespace App\View\Components\Feed;

use App\Models\Post;
use App\Services\PostVoteResultService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class PostCard extends Component
{
    public ?array $originDistribution = null;

    public ?array $cuisineDistribution = null;

    public bool $canDeletePost = false;

    public bool $canReportPost = false;

    public function __construct(
        public Post $post,
        public bool $selected = false,
    ) {
        if (! $post->exists) {
            return;
        }

        $user = auth()->user();
        $voteResults = app(PostVoteResultService::class);

        $this->originDistribution = $voteResults->originDistribution($post, $user);
        $this->cuisineDistribution = $voteResults->cuisineDistribution($post, $user);
        $this->canDeletePost = $user?->can('deleteFromFeed', $post) ?? false;
        $this->canReportPost = $user?->can('report', $post) ?? false;
    }

    public function render(): View
    {
        return view('components.feed.post-card');
    }
}
