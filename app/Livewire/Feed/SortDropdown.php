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
        $options = [
            'newest' => 'Newest',
            'top' => 'Top',
            'hot' => 'Hot',
        ];

        return view('livewire.feed.sort-dropdown', [
            'options' => $options,
            'currentLabel' => $options[$this->sort] ?? 'Newest',
        ]);
    }
}
