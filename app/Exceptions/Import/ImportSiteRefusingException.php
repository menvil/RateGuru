<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * A site that refused this server a link (ImportRefusalMonitor): reported,
 * never thrown, so the error tracker gathers every refusal as one issue of
 * its own and can alert on it.
 */
final class ImportSiteRefusingException extends RuntimeException
{
    public static function make(string $site, string $reason): self
    {
        return new self("{$site} refused a link ({$reason}); it may be blocking this server.");
    }
}
