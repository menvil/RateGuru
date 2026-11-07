<?php

namespace App\Livewire\Settings;

use App\Actions\Users\UpdateNotificationPreferencesAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NotificationPreferencesForm extends Component
{
    public mixed $notify_followed_author_posts = true;

    /** Whether the last save was written, for the form to say so until the next change. */
    public bool $saved = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->notify_followed_author_posts = (bool) $user->notify_followed_author_posts;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function save(): void
    {
        $this->saved = false;

        $this->validate([
            'notify_followed_author_posts' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $this->saved = app(UpdateNotificationPreferencesAction::class)->handle(
            $user,
            (bool) $this->notify_followed_author_posts,
        );
    }

    public function render(): View
    {
        return view('livewire.settings.notification-preferences-form');
    }
}
