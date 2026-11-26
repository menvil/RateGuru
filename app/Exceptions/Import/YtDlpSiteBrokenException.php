<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * Reported — never thrown at a visitor — when yt-dlp has taken no video from
 * a site's recent pages on two days running: its extractor likely broke on a
 * change of the site's, and a newer yt-dlp is what usually fixes it. Or when
 * yt-dlp would not run at all. Reported, it reaches Sentry and, where it
 * runs, Nightwatch.
 */
final class YtDlpSiteBrokenException extends RuntimeException
{
    public static function site(string $extractor, int $failures): self
    {
        return new self("yt-dlp took no video from {$extractor} on {$failures} days running.");
    }

    /** yt-dlp itself would not run — missing, or broken by an update — so no site could be checked. */
    public static function toolUnavailable(): self
    {
        return new self('yt-dlp could not be run, so no site was checked.');
    }

    public static function outOfTime(): self
    {
        return new self('The yt-dlp site check ran out of time, so no site was counted.');
    }
}
