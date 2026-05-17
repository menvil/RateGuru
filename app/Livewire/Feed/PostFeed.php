<?php

namespace App\Livewire\Feed;

use App\Queries\Feed\FeedQuery;
use Livewire\Component;

class PostFeed extends Component
{
    public function render(FeedQuery $feedQuery)
    {
        return view('livewire.feed.post-feed', [
            'posts' => $feedQuery->get(sort: 'newest'),
        ]);
    }
}
