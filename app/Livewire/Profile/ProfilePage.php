<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Queries\SavedPosts\SavedPostsQuery;
use App\Queries\UserPublicPostsQuery;
use App\Support\Profile\ProfileStats;
use App\Support\Profile\ProfileStatsData;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
            ->firstOrFail();
    }

    public function getStatsProperty(): ProfileStatsData
    {
        return app(ProfileStats::class)->forUser($this->profileUser, auth()->user());
    }

    /** @return LengthAwarePaginator<int, \App\Models\Post> */
    public function getPostsProperty(): LengthAwarePaginator
    {
        return app(UserPublicPostsQuery::class)->forProfile($this->profileUser);
    }

    /** @return LengthAwarePaginator<int, \App\Models\Post>|null */
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

    public function setTab(string $tab): void
    {
        $allowed = ['posts', 'activity'];
        if ($this->isOwner) {
            $allowed[] = 'saved';
        }
        if (in_array($tab, $allowed, true)) {
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
