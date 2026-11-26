<?php

namespace App\Support\Import;

/**
 * The sites a video may be taken from with yt-dlp (import.ytdlp.sites), each
 * with the one extractor yt-dlp may use for it. A link to any other host is
 * never given to yt-dlp.
 */
final class YtDlpSites
{
    /** The extractor for a link's host, or null when its site is not one of them. */
    public function extractorFor(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($host === '' || $scheme !== 'https') {
            return null;
        }

        foreach ($this->sites() as $domain => $extractor) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $extractor;
            }
        }

        return null;
    }

    /** @return list<string> every extractor yt-dlp may use, for --use-extractors */
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
