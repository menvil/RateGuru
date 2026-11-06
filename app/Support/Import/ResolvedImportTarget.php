<?php

namespace App\Support\Import;

/**
 * The outcome of UrlImportValidator::validate(): a single hop's URL together
 * with the exact IP that was resolved and validated for it. This is the
 * whole point of the pinning design — whatever IP is on this object is the
 * only IP the transport is ever allowed to connect to for $url; it never
 * performs its own, independent DNS lookup.
 */
final readonly class ResolvedImportTarget
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}
}
