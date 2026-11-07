<?php

namespace App\Livewire\SavedPosts;

use App\Queries\SavedPosts\SavedPostsQuery;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Rating\RatingVotingStateLoader;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class SavedPostsPage extends Component
{
    use WithPagination;

    /**
     * Saved posts are a feature the project can switch off; the page goes
     * with it, on every request: a page open when they were switched off
     * turns no further page of them.
     */
    public function boot(ProjectSettingsManager $settings): void
    {
        abort_unless($settings->featureEnabled('show_saved_posts'), 404);
    }

    public function render(
        SavedPostsQuery $query,
        RatingConfigurationManager $ratingConfiguration,
        RatingVotingStateLoader $ratingVotingStateLoader,
    ): View {
        $savedPosts = $query->forUser(auth()->user());

        // A page past the end — the last saves on it undone, or an old link —
        // shows the last page there is, not "no saved posts".
        if ($savedPosts->isEmpty() && $savedPosts->total() > 0) {
            $this->setPage($savedPosts->lastPage());
            $savedPosts = $query->forUser(auth()->user());
        }
        $ratingGroups = $ratingConfiguration->activeGroups();

        return view('livewire.saved-posts.saved-posts-page', [
            'savedPosts' => $savedPosts,
            'ratingGroups' => $ratingGroups,
            'ratingVotingStates' => $ratingVotingStateLoader->forPosts(
                $savedPosts->getCollection(),
                auth()->user(),
                $ratingGroups,
            ),
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
