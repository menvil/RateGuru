<?php

namespace App\Support\Import;

/**
 * Per-fetch limits handed to the transport layer — deliberately narrow (byte
 * cap + timeouts only) rather than a generic options bag, so a transport
 * implementation can't grow ad-hoc behavior switches over time.
 */
final readonly class ImportFetchPolicy
{
    public function __construct(
        public int $maxBytes,
        public int $timeoutSeconds,
        public int $connectTimeoutSeconds,
    ) {}
}
