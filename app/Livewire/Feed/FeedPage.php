<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class FeedPage extends Component
{
    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: null)]
    public ?string $category = null;

    #[Url(as: 'sort', except: 'newest')]
    public string $sort = 'newest';

    public ?int $selectedPostId = null;

    public function mount(): void
    {
        $this->normalizeSort();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
    }

    #[On('open-post-drawer')]
    public function openPostDrawer(int $postId): void
    {
        $this->selectedPostId = $postId;
        $this->dispatch('post-drawer-opened');
    }

    public function closePostDrawer(): void
    {
        $this->selectedPostId = null;
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
