<?php

namespace App\Livewire\Notifications;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class NotificationBell extends Component
{
    public function getUnreadCountProperty(): int
    {
        if (! auth()->check()) {
            return 0;
        }

        return auth()->user()
            ->unreadNotifications()
            ->count();
    }

    /**
     * @return Collection<int, \Illuminate\Notifications\DatabaseNotification>
     */
    public function getNotificationsProperty(): Collection
    {
        if (! auth()->check()) {
            return new Collection();
        }

        return auth()->user()
            ->notifications()
            ->latest()
            ->limit(10)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.notifications.notification-bell');
    }
}
