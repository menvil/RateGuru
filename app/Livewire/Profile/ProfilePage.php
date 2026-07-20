<?php

namespace App\Livewire\Profile;

use App\Enums\ProfileActivityVisibility;
use App\Models\Post;
use App\Models\RatingVote;
use App\Models\User;
use App\Queries\SavedPosts\SavedPostsQuery;
use App\Queries\UserPublicPostsQuery;
use App\Queries\UserRatingActivityQuery;
use App\Support\Profile\ProfileStats;
use App\Support\Profile\ProfileStatsData;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** @property-read bool $isOwner */
final class ProfilePage extends Component
{
    use WithPagination;

    public User $profileUser;

    #[Url]
    public string $tab = 'posts';

    public function mount(string $username): void
    {
        $this->profileUser = User::query()
            ->where('username', $username)
            ->withCount(['followerRelations', 'followingRelations'])
            ->firstOrFail();

        if (! in_array($this->tab, $this->getAllowedTabs(), true)) {
            $this->tab = 'posts';
        }
    }

    /** @return list<string> */
    private function getAllowedTabs(): array
    {
        $tabs = ['posts', 'activity'];

        if (auth()->id() === $this->profileUser->id) {
            $tabs[] = 'saved';
        }

        return $tabs;
    }

    public function getStatsProperty(): ProfileStatsData
    {
        return app(ProfileStats::class)->forUser($this->profileUser, auth()->user());
    }

    /** @return LengthAwarePaginator<int, Post> */
    public function getPostsProperty(): LengthAwarePaginator
    {
        return app(UserPublicPostsQuery::class)->forProfile($this->profileUser);
    }

    public function getCanSeeActivityProperty(): bool
    {
        return $this->isOwner
            || $this->profileUser->rating_activity_visibility === ProfileActivityVisibility::Public;
    }

    /** @return Collection<int, RatingVote> */
    public function getRatingActivityProperty(): Collection
    {
        return app(UserRatingActivityQuery::class)->forProfile($this->profileUser, auth()->user());
    }

    /** @return LengthAwarePaginator<int, Post>|null */
    public function getSavedPostsProperty(): ?LengthAwarePaginator
    {
        if (! $this->isOwner) {
            return null;
        }

        return app(SavedPostsQuery::class)->forUser($this->profileUser);
    }

    public function getDisplayNameProperty(): string
    {
        return $this->profileUser->display_name
            ?: ($this->profileUser->name ?: $this->profileUser->username);
    }

    public function getIsOwnerProperty(): bool
    {
        return auth()->id() === $this->profileUser->id;
    }

    public function getCanSeeFollowButtonProperty(): bool
    {
        return auth()->check()
            && auth()->id() !== $this->profileUser->id
            && app(ProjectSettingsManager::class)->featureEnabled('show_follow_buttons');
    }

    public function getCanSeeReportUserPlaceholderProperty(): bool
    {
        return auth()->check()
            && auth()->id() !== $this->profileUser->id;
    }

    #[On('follow-state-changed')]
    public function onFollowStateChanged(int $authorId): void
    {
        if ($authorId === $this->profileUser->id) {
            $this->profileUser->loadCount(['followerRelations', 'followingRelations']);
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, $this->getAllowedTabs(), true)) {
            $this->tab = $tab;
            $this->resetPage();
        }
    }

    public function render(): View
    {
        return view('livewire.profile.profile-page')
            ->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
