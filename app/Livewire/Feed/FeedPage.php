<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class FeedPage extends Component
{
    public string $search = '';

    public ?string $category = null;

    public string $sort = 'newest';

    public function mount(): void
    {
        $this->normalizeSort();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
    }

    private function normalizeSort(): void
    {
        if (! in_array($this->sort, ['newest', 'top', 'hot'], true)) {
            $this->sort = 'newest';
        }
    }

    public function render(): View
    {
        return view('livewire.feed.feed-page');
    }
}
