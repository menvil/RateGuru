<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class SortDropdown extends Component
{
    #[Modelable]
    public string $sort = 'newest';

    public function render(): View
    {
        return view('livewire.feed.sort-dropdown', [
            'options' => [
                'newest' => 'Newest',
                'top'    => 'Top',
                'hot'    => 'Hot',
            ],
        ]);
    }
}
