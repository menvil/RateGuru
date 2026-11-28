<?php

namespace App\Exceptions\Import;

/**
 * A link to a site the administrators blocked in Project settings
 * (BlockedImportDomains). An unsafe address to every check that skips one —
 * an og:image on that site is passed over like any other it may not fetch —
 * and told to the author as a site that is not supported.
 */
final class ImportDomainBlockedException extends UnsafeImportUrlException
{
    public static function make(string $host): self
    {
        return new self("Imports from {$host} are blocked in Project settings.");
    }
}
