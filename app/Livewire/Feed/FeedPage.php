<?php

namespace App\Livewire\Feed;

use App\Services\Feed\FeedPostDeletionService;
use App\Support\View\AppLayoutData;
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

    public ?string $deleteError = null;

    public function mount(): void
    {
        $this->normalizeSort();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
    }

    #[On('select-post')]
    public function selectPost(int $postId): void
    {
        $this->selectedPostId = $postId;

        $this->dispatch('post-selected', postId: $postId);
    }

    #[On('clear-selected-post')]
    public function clearSelectedPost(): void
    {
        $this->selectedPostId = null;
    }

    #[On('delete-post')]
    public function deletePost(int $postId, FeedPostDeletionService $feedPostDeletionService): void
    {
        $this->deleteError = null;

        $result = $feedPostDeletionService->deleteForUser(auth()->user(), $postId);

        if ($result->error !== null) {
            $this->deleteError = $result->error;

            return;
        }

        if (! $result->deleted) {
            return;
        }

        if ($this->selectedPostId === $postId) {
            $this->selectedPostId = null;
        }

        $this->dispatch('post-deleted', postId: $postId);
    }

    public function openPostDrawer(int $postId): void
    {
        $this->selectPost($postId);
    }

    public function closePostDrawer(): void
    {
        $this->clearSelectedPost();
    }

    private function normalizeSort(): void
    {
        if (! in_array($this->sort, ['newest', 'top', 'hot'], true)) {
            $this->sort = 'newest';
        }
    }

    public function render(): View
    {
        return view('livewire.feed.feed-page')
            ->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
