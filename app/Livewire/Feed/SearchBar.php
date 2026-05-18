<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class SearchBar extends Component
{
    #[Modelable]
    public string $search = '';

    public function render(): View
    {
        return view('livewire.feed.search-bar');
    }
}
