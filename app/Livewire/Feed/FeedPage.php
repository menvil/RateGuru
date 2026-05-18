<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class FeedPage extends Component
{
    public string $search = '';

    public function render(): View
    {
        return view('livewire.feed.feed-page');
    }
}
