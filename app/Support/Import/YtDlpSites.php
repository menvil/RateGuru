<?php

namespace App\Support\Import;

/**
 * Which extractor yt-dlp may use for a link.
 *
 * - The named sites (import.ytdlp.sites), each with the one extractor yt-dlp
 *   may use there, held to their own rules.
 * - Any other page (import.ytdlp.any_site) with every extractor yt-dlp has
 *   but the generic one — which would take a file from any page at all — and
 *   only where yt-dlp runs as its own account behind the firewall: every
 *   extractor opens what a page hands it. Never a file's link, nor a post on
 *   X (its own reader takes its video), nor a blocked host
 *   (import.ytdlp.blocked_domains).
 */
final class YtDlpSites
{
    /** "Every extractor but the generic", as --use-extractors takes it. */
    public const string ANY = 'default,-generic';

    /** The extractor for a link: a named site's, ANY for any other page, or null for none. */
    public function extractorFor(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($host === '' || $scheme !== 'https') {
            return null;
        }

        foreach ($this->sites() as $domain => $extractor) {
            if (self::onDomain($host, $domain)) {
                return $extractor;
            }
        }

        return $this->anySite($url, $host) ? self::ANY : null;
    }

    /** Whether the extractor is a named site's, held to its own rules, rather than ANY. */
    public function isNamed(?string $extractor): bool
    {
        return $extractor !== null && $extractor !== self::ANY;
    }

    private function anySite(string $url, string $host): bool
    {
        if (! (bool) config('import.ytdlp.any_site')) {
            return false;
        }

        foreach ((array) config('import.ytdlp.blocked_domains', []) as $domain) {
            if (is_string($domain) && $domain !== '' && self::onDomain($host, strtolower($domain))) {
                return false;
            }
        }

        if (in_array(app(ImportProviderDetector::class)->detect($url), ['direct_image', 'direct_video', 'x'], true)) {
            return false;
        }

        return app(YtDlpIsolation::class)->isolated();
    }

    private static function onDomain(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /** @return list<string> the named sites' extractors */
    public function extractors(): array
    {
        return array_values(array_unique(array_values($this->sites())));
    }

    /** @return array<string, string> */
    private function sites(): array
    {
        return array_filter(
            (array) config('import.ytdlp.sites', []),
            fn (mixed $extractor, mixed $domain): bool => is_string($domain) && is_string($extractor) && $extractor !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
