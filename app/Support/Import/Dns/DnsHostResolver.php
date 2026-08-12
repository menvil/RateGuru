<?php

namespace App\Support\Import\Dns;

/**
 * dns_get_record()-backed A/AAAA lookup. Bounds how many records it will
 * even look at — a resolver answering with a pathological number of records
 * is a signal not to trust the response, independent of validating any
 * individual answer.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $maxAnswers = max(1, (int) config('import.dns_max_answers', 16));

        // Suppressed: a resolver failure raises a PHP warning (ErrorException
        // under Laravel); an unresolvable host must fail as "no answers"
        // instead of throwing here.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return [];
        }

        $ips = [];

        foreach (array_slice($records, 0, $maxAnswers) as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
