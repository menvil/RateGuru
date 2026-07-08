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
            'newest' => __('ui.feed.sort.newest'),
            'top'    => __('ui.feed.sort.top'),
            'hot'    => __('ui.feed.sort.hot'),
        ];

        return view('livewire.feed.sort-dropdown', [
            'options' => $options,
            'currentLabel' => $options[$this->sort] ?? __('ui.feed.sort.newest'),
        ]);
    }
}
