<?php

namespace App\Livewire\Follows;

use App\Actions\Follows\ToggleFollowAuthorAction;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Follows\CannotFollowSelfException;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\User;
use App\Support\Follows\FollowState;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class FollowButton extends Component
{
    public User $author;

    public bool $isFollowing = false;

    public ?string $message = null;

    public string $variant = 'full';

    public function mount(User $author, string $variant = 'full'): void
    {
        $this->author = $author;
        $this->variant = $variant;

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
        } catch (FollowFeatureDisabledException) {
            $this->message = __('follows.feature_disabled');
        } catch (CannotFollowSelfException) {
            return;
        } catch (CannotFollowAuthorException) {
            $this->message = __('follows.cannot_follow_author');
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
