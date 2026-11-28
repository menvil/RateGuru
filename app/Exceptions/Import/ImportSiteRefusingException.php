<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * A site that refused this server for many different links in a short
 * while (ImportRefusalMonitor): reported, never thrown, so the error tracker
 * shows it as an issue of its own, one per site, and can alert on it.
 */
final class ImportSiteRefusingException extends RuntimeException
{
    public static function make(string $site, int $links, string $lastReason): self
    {
        return new self("{$site} refused {$links} different links in a short while (last: {$lastReason}); it may be blocking this server.");
    }
}
