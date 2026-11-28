<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportSiteRefusingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells a site that has started refusing this server — answering "too many
 * requests" or "forbidden", or asking yt-dlp to prove it is no robot — to the
 * error tracker, at once, from the first link: with few authors, waiting for
 * many would leave a block unseen for long. Each different link refused is
 * reported, with the link itself, so the story can be followed: the error
 * tracker gathers them into one issue. A link refused again within the window
 * — a retry, a second author — is reported once. One refusal may still be an
 * author's private post; that is for whoever reads the issue to tell.
 * import.refusal_alert.distinct_links raises how many different links of one
 * site it takes, should single refusals turn out too noisy.
 */
final class ImportRefusalMonitor
{
    public function record(string $url, string $reason): void
    {
        $site = self::site($url);

        if ($site === null) {
            return;
        }

        $link = self::shown($url);
        $window = max(60, (int) config('import.refusal_alert.window_seconds'));
        $prefix = 'import-refusal:'.$site.':'.intdiv(time(), $window);

        Log::warning('Import: a site refused a link.', ['site' => $site, 'link' => $link, 'reason' => $reason]);

        if (! Cache::add($prefix.':link:'.sha1($url), true, $window * 2)) {
            return;
        }

        Cache::add($prefix.':count', 0, $window * 2);
        $count = (int) Cache::increment($prefix.':count');

        if ($count < max(1, (int) config('import.refusal_alert.distinct_links'))) {
            return;
        }

        report(ImportSiteRefusingException::make($site, $link, $reason));
    }

    /** The site a link is of — its host, without "www." — or null for no host. */
    private static function site(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' ? null : (str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }

    /**
     * The link as it is reported: its scheme, host, port and path. Never its
     * query, fragment or credentials — a signed link or a token pasted with it
     * is not for the error tracker — and never longer than a page address.
     */
    private static function shown(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return mb_strimwidth("{$scheme}://{$host}{$port}".($parts['path'] ?? ''), 0, 500, '…');
    }
}
