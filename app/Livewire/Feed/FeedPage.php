<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class FeedPage extends Component
{
    public string $search = '';

    public ?string $category = null;

    public function render(): View
    {
        return view('livewire.feed.feed-page');
    }
}
