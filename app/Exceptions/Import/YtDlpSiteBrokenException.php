<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * Reported — never thrown at a visitor — when yt-dlp has taken no video from
 * a site's recent pages two daily checks running: its extractor likely broke
 * on a change of the site's, and a newer yt-dlp is what usually fixes it.
 * Reported, it reaches Sentry and, where it runs, Nightwatch.
 */
final class YtDlpSiteBrokenException extends RuntimeException
{
    public static function site(string $extractor, int $failures): self
    {
        return new self("yt-dlp took no video from {$extractor} in {$failures} daily checks running.");
    }
}
