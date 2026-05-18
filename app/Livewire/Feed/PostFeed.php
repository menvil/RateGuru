<?php

namespace App\Livewire\Feed;

use App\Queries\Feed\FeedQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PostFeed extends Component
{
    public ?string $search = null;

    public ?string $tag = null;

    public string $sort = 'newest';

    public function render(FeedQuery $feedQuery): View
    {
        return view('livewire.feed.post-feed', [
            'posts' => $feedQuery->get(
                search: $this->search !== '' ? $this->search : null,
                tag: $this->tag !== '' ? $this->tag : null,
                sort: $this->sort,
            ),
        ]);
    }
}
