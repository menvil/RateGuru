<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NotificationPreferencesForm extends Component
{
    public mixed $notify_followed_author_posts = true;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->notify_followed_author_posts = (bool) $user->notify_followed_author_posts;
    }

    public function save(): void
    {
        $this->validate([
            'notify_followed_author_posts' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $user->update([
            'notify_followed_author_posts' => $this->notify_followed_author_posts,
        ]);
    }

    public function render(): View
    {
        return view('livewire.settings.notification-preferences-form');
    }
}
