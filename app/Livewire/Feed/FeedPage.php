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

    #[Url(as: 'origin', except: [])]
    public mixed $origin = [];

    #[Url(as: 'cuisine', except: [])]
    public mixed $cuisine = [];

    #[Url(as: 'sort', except: 'newest')]
    public string $sort = 'newest';

    public ?int $selectedPostId = null;

    public ?string $deleteError = null;

    public function mount(): void
    {
        $this->normalizeSort();
        $this->normalizeFilters();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
    }

    public function updatedOrigin(): void
    {
        $this->normalizeFilters();
    }

    public function updatedCuisine(): void
    {
        $this->normalizeFilters();
    }

    public function toggleOrigin(string $origin): void
    {
        $this->origin = $this->toggleFilterValue($this->origin, $origin, ['homemade', 'restaurant']);
    }

    public function clearOriginFilters(): void
    {
        $this->origin = [];
    }

    public function toggleCuisine(string $cuisine): void
    {
        $this->cuisine = $this->toggleFilterValue($this->cuisine, $cuisine, ['italian', 'asian', 'american', 'mexican', 'other']);
    }

    public function clearCuisineFilters(): void
    {
        $this->cuisine = [];
    }

    #[On('select-post')]
    public function selectPost(int $postId, ?string $focus = null): void
    {
        $this->selectedPostId = $postId;

        $this->dispatch('post-selected', postId: $postId, focus: $focus);
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

    private function normalizeFilters(): void
    {
        $this->origin = $this->normalizeFilterValues($this->origin, ['homemade', 'restaurant']);
        $this->cuisine = $this->normalizeFilterValues($this->cuisine, ['italian', 'asian', 'american', 'mexican', 'other']);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function normalizeFilterValues(mixed $value, array $allowed): array
    {
        return collect((array) $value)
            ->filter(fn ($item): bool => is_string($item) && in_array($item, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function toggleFilterValue(mixed $current, string $value, array $allowed): array
    {
        if (! in_array($value, $allowed, true)) {
            return $this->normalizeFilterValues($current, $allowed);
        }

        $values = $this->normalizeFilterValues($current, $allowed);

        if (in_array($value, $values, true)) {
            return array_values(array_diff($values, [$value]));
        }

        $values[] = $value;

        return $this->normalizeFilterValues($values, $allowed);
    }

    public function effectiveSearch(): ?string
    {
        $search = trim($this->search);

        return strlen($search) >= 3 ? $search : null;
    }

    public function render(): View
    {
        return view('livewire.feed.feed-page')
            ->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
