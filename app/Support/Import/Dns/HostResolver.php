<?php

namespace App\Support\Import\Dns;

/**
 * Resolves a hostname to its A/AAAA answers. The only implementation of this
 * ever bound in production is DnsHostResolver; tests bind a fake so the
 * suite never depends on live DNS.
 */
interface HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
