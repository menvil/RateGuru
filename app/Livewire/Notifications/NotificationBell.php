<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Component;

final class NotificationBell extends Component
{
    private const SHOWN = 10;

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
     * @return Collection<int, DatabaseNotification>
     */
    public function getNotificationsProperty(): Collection
    {
        if (! auth()->check()) {
            return new Collection;
        }

        // Unread ones first, so every one the badge counts can be reached
        // and marked read; read ones fill whatever room is left. The id breaks
        // a tie in time, so the same ones are shown on every render.
        $user = auth()->user();
        $unread = $user->unreadNotifications()->latest()->orderByDesc('id')->limit(self::SHOWN)->get();

        if ($unread->count() >= self::SHOWN) {
            return $unread;
        }

        return $unread->concat($user->readNotifications()->latest()->orderByDesc('id')->limit(self::SHOWN - $unread->count())->get());
    }

    public function markAsRead(string $notificationId): void
    {
        if (! auth()->check() || ! Str::isUuid($notificationId)) {
            return;
        }

        $notification = auth()->user()
            ->notifications()
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            return;
        }

        $notification->markAsRead();
    }

    public function render(): View
    {
        return view('livewire.notifications.notification-bell');
    }
}
