<?php

namespace App\Livewire\SavedPosts;

use App\Queries\SavedPosts\SavedPostsQuery;
use App\Support\Feed\PostCardViewerState;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Rating\RatingVotingStateLoader;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class SavedPostsPage extends Component
{
    use WithPagination;

    public function render(
        SavedPostsQuery $query,
        RatingConfigurationManager $ratingConfiguration,
        RatingVotingStateLoader $ratingVotingStateLoader,
        PostCardViewerState $viewerState,
    ): View {
        $savedPosts = $query->forUser(auth()->user());
        $ratingGroups = $ratingConfiguration->activeGroups();

        return view('livewire.saved-posts.saved-posts-page', [
            'savedPosts' => $savedPosts,
            'ratingGroups' => $ratingGroups,
            'ratingVotingStates' => $ratingVotingStateLoader->forPosts(
                $savedPosts->getCollection(),
                auth()->user(),
                $ratingGroups,
            ),
            'viewerStates' => $viewerState->forPosts($savedPosts->getCollection(), auth()->user()),
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
