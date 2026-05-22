<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class NotificationBell extends Component
{
    public function render(): View
    {
        return view('livewire.notifications.notification-bell');
    }
}
