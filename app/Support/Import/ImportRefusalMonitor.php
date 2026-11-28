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
 * requests" or "forbidden", or asking yt-dlp to prove it is no robot — at
 * once, from the first link: with few authors, waiting for many would leave
 * a block unseen for long. Each different link refused is reported — an
 * error of its own, which the error tracker gathers into one issue — and
 * every administrator is told in their notifications, once per site within
 * a window. import.refusal_alert.distinct_links raises how many different
 * links of one site it takes, should one refusal turn out too noisy.
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

        if ($count < max(1, (int) config('import.refusal_alert.distinct_links'))) {
            return;
        }

        report(ImportSiteRefusingException::make($site, $reason));

        if (! Cache::add($prefix.':alerted', true, $window * 2)) {
            return;
        }

        // Each through its own notify(), which keeps the rules every
        // notification of the site's follows.
        User::query()->where('role', UserRole::Admin)->each(
            fn (User $admin) => $admin->notify(new ImportSiteRefusingNotification($site)),
        );
    }

    /** The site a link is of — its host, without "www." — or null for no host. */
    private static function site(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' ? null : (str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }
}
