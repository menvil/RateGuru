<?php

namespace App\Livewire\Notifications;

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

    public function render(): View
    {
        return view('livewire.notifications.notification-bell');
    }
}
