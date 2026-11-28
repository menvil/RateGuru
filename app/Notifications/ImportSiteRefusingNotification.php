<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells an administrator that a site has refused this server a link — it may
 * be blocking it — and that authors are asked to upload the file instead
 * (ImportRefusalMonitor). Once per site in a while; every refusal is in the
 * error tracker.
 */
final class ImportSiteRefusingNotification extends Notification
{
    public function __construct(public readonly string $site) {}

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
            'message_params' => ['site' => $this->site],
            'url' => '#',
        ];
    }
}
