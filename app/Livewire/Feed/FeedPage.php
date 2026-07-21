<?php

namespace App\Livewire\Feed;

use App\Models\RatingGroup;
use App\Models\User;
use App\Queries\Feed\MatchedUsersQuery;
use App\Services\Feed\FeedPostDeletionService;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class FeedPage extends Component
{
    private RatingConfigurationManager $ratingConfiguration;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'tag', except: null)]
    public ?string $tag = null;

    #[Url(as: 'category', except: [])]
    public mixed $category = [];

    #[Url(as: 'ratings', except: [])]
    public mixed $ratings = [];

    #[Url(as: 'sort', except: 'newest')]
    public string $sort = 'newest';

    #[Url(as: 'feed', except: null)]
    public ?string $feed = null;

    public ?int $selectedPostId = null;

    public ?string $deleteError = null;

    public function boot(RatingConfigurationManager $ratingConfiguration): void
    {
        $this->ratingConfiguration = $ratingConfiguration;
    }

    public function mount(): void
    {
        $this->normalizeSort();
        $this->normalizeFilters();
        $this->normalizeFeed();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
    }

    public function updatedCategory(): void
    {
        $this->normalizeFilters();
    }

    public function updatedRatings(): void
    {
        $this->normalizeFilters();
    }

    public function toggleCategory(string $category): void
    {
        $this->category = $this->toggleFilterValue($this->category, $category, $this->categoryValues());
    }

    public function clearCategoryFilters(): void
    {
        $this->category = [];
    }

    public function toggleRatingOption(string $groupKey, string $optionKey): void
    {
        $group = $this->activeGroups()->firstWhere('key', $groupKey);

        if ($group === null) {
            $this->normalizeFilters();

            return;
        }

        $ratings = is_array($this->ratings) ? $this->ratings : [];
        $ratings[$groupKey] = $this->toggleFilterValue(
            $ratings[$groupKey] ?? [],
            $optionKey,
            $group->options->pluck('key')->all(),
        );
        $this->ratings = $ratings;
        $this->normalizeFilters();
    }

    public function clearRatingGroupFilters(string $groupKey): void
    {
        $ratings = is_array($this->ratings) ? $this->ratings : [];
        unset($ratings[$groupKey]);
        $this->ratings = $ratings;
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

    /** @return Collection<int, RatingGroup> */
    #[Computed]
    public function activeGroups(): Collection
    {
        return $this->ratingConfiguration->activeGroups();
    }

    private function normalizeSort(): void
    {
        if (! in_array($this->sort, ['newest', 'top', 'hot'], true)) {
            $this->sort = 'newest';
        }
    }

    private function normalizeFeed(): void
    {
        if ($this->feed !== 'following' || ! auth()->check()) {
            $this->feed = null;
        }
    }

    public function isFollowingFeed(): bool
    {
        return $this->feed === 'following' && auth()->check();
    }

    private function normalizeFilters(): void
    {
        $this->category = $this->normalizeFilterValues($this->category, $this->categoryValues());
        $this->ratings = $this->normalizeRatingFilters($this->ratings);
    }

    /** @return list<string> */
    private function categoryValues(): array
    {
        return $this->activeGroups()->first()?->options->pluck('key')->all() ?? [];
    }

    /** @return array<string, list<string>> */
    private function normalizeRatingFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $normalized = [];

        foreach ($this->activeGroups() as $group) {
            $values = $this->normalizeFilterValues(
                $filters[$group->key] ?? [],
                $group->options->pluck('key')->all(),
            );

            if ($values !== []) {
                $normalized[$group->key] = $values;
            }
        }

        return $normalized;
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

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    #[Computed]
    public function matchedUsers(): \Illuminate\Database\Eloquent\Collection
    {
        $search = $this->effectiveSearch();

        if ($search === null) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return app(MatchedUsersQuery::class)->search($search);
    }

    public function render(): View
    {
        return view('livewire.feed.feed-page')
            ->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
