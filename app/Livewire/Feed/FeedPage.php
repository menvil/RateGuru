<?php

namespace App\Livewire\Feed;

use App\Actions\Posts\DeletePostAction;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
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
    public function deletePost(int $postId, DeletePostAction $deletePostAction): void
    {
        $this->deleteError = null;

        if (! auth()->check()) {
            return;
        }

        $post = Post::query()->find($postId);

        if ($post === null) {
            return;
        }

        try {
            $deletePostAction->handle(auth()->user(), $post);
        } catch (CannotDeletePostException $e) {
            $this->deleteError = $e->getMessage();

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
        return view('livewire.feed.feed-page');
    }
}
