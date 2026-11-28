<?php

namespace App\Support\Import;

use App\Enums\UserRole;
use App\Exceptions\Import\ImportSiteRefusingException;
use App\Models\User;
use App\Notifications\ImportSiteRefusingNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells a site that has started refusing this server — answering "too many
 * requests" or "forbidden", or asking yt-dlp to prove it is no robot — from
 * a link that simply cannot be taken. One refusal says nothing: an author's
 * private post is refused too. Refusals of as many different links of one
 * site as import.refusal_alert.distinct_links, within one window, look like
 * the site blocking the server: that is reported once per window — an error
 * of its own, for the error tracker to alert on — and every administrator is
 * told in their notifications.
 */
final class ImportRefusalMonitor
{
    public function record(string $url, string $reason): void
    {
        $site = self::site($url);

        if ($site === null) {
            return;
        }

        $window = max(60, (int) config('import.refusal_alert.window_seconds'));
        $prefix = 'import-refusal:'.$site.':'.intdiv(time(), $window);

        Log::warning('Import: a site refused a link.', ['site' => $site, 'reason' => $reason]);

        // The same link refused again — a retry, a second author — counts once.
        if (! Cache::add($prefix.':link:'.sha1($url), true, $window * 2)) {
            return;
        }

        Cache::add($prefix.':count', 0, $window * 2);
        $count = (int) Cache::increment($prefix.':count');

        if ($count < max(1, (int) config('import.refusal_alert.distinct_links')) || ! Cache::add($prefix.':alerted', true, $window * 2)) {
            return;
        }

        report(ImportSiteRefusingException::make($site, $count, $reason));

        // Each through its own notify(), which keeps the rules every
        // notification of the site's follows.
        User::query()->where('role', UserRole::Admin)->each(
            fn (User $admin) => $admin->notify(new ImportSiteRefusingNotification($site, $count)),
        );
    }

    /** The site a link is of — its host, without "www." — or null for no host. */
    private static function site(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' ? null : (str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }
}
