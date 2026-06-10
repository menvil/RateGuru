<?php

namespace App\Livewire\SavedPosts;

use App\Queries\SavedPosts\SavedPostsQuery;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class SavedPostsPage extends Component
{
    use WithPagination;

    public function render(SavedPostsQuery $query): View
    {
        $savedPosts = $query->forUser(auth()->user());

        return view('livewire.saved-posts.saved-posts-page', [
            'savedPosts' => $savedPosts,
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
