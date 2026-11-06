<?php

namespace App\Livewire\Follows;

use App\Actions\Follows\ToggleFollowAuthorAction;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\User;
use App\Support\Follows\FollowState;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class FollowButton extends Component
{
    #[Locked]
    public User $author;

    public bool $isFollowing = false;

    public ?string $message = null;

    public function mount(User $author): void
    {
        $this->author = $author;

        if (! app(ProjectSettingsManager::class)->featureEnabled('show_follow_buttons')) {
            return;
        }

        $viewer = auth()->user();

        if ($viewer === null || $viewer->id === $author->id) {
            return;
        }

        $this->isFollowing = app(FollowState::class)->isFollowing($viewer, $author);
    }

    public function toggle(ToggleFollowAuthorAction $action): void
    {
        $viewer = auth()->user();

        if ($viewer === null) {
            $this->message = __('follows.login_required');

            return;
        }

        if ($viewer->id === $this->author->id) {
            return;
        }

        try {
            $result = $action->handle($viewer, $this->author);
            $this->isFollowing = $result->isFollowing;
            $this->message = null;
            $this->dispatch('follow-state-changed', authorId: $this->author->id, isFollowing: $result->isFollowing);
        } catch (FollowFeatureDisabledException) {
            $this->message = __('follows.feature_disabled');
        } catch (CannotFollowAuthorException) {
            $this->message = __('follows.cannot_follow_author');
        }
    }

    #[On('follow-state-changed')]
    public function syncFollowState(int $authorId, bool $isFollowing): void
    {
        if ($authorId === $this->author->id) {
            $this->isFollowing = $isFollowing;
            $this->message = null;
        }
    }

    public function isSelf(): bool
    {
        return auth()->check() && auth()->id() === $this->author->id;
    }

    public function isFeatureEnabled(): bool
    {
        return app(ProjectSettingsManager::class)->featureEnabled('show_follow_buttons');
    }

    public function render(): View
    {
        return view('livewire.follows.follow-button');
    }
}
