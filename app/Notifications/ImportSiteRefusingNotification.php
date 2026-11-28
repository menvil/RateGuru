<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells an administrator that a site has refused this server for many
 * different links lately — it may be blocking it — and that authors are
 * asked to upload the file instead (ImportRefusalMonitor).
 */
final class ImportSiteRefusingNotification extends Notification
{
    public function __construct(public readonly string $site, public readonly int $links) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'import_site_refusing',
            'message_key' => 'ui.notifications.messages.import_site_refusing',
            'message_params' => ['site' => $this->site, 'count' => $this->links],
            'url' => '#',
        ];
    }
}
