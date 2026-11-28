<?php

namespace App\Support\Geo\Networks;

/**
 * A list of networks by ASN, one "AS1234" a line with anything after it a
 * comment — the form of X4BNet's curated hosting and VPN lists — read into
 * sorted, distinct numbers. Lines that name no ASN are skipped.
 */
final class HostingAsnList
{
    /** @return list<int> */
    public static function parse(string $text): array
    {
        preg_match_all('/^\s*AS(\d{1,10})\b/mi', $text, $matches);
        $asns = array_values(array_unique(array_filter(array_map('intval', $matches[1]), fn (int $asn): bool => $asn > 0 && $asn <= 4294967295)));
        sort($asns);

        return $asns;
    }

    /**
     * The lists, joined: every ASN any of them names, once.
     *
     * @param  list<list<int>>  $lists
     * @return list<int>
     */
    public static function union(array $lists): array
    {
        $asns = array_values(array_unique(array_merge(...$lists ?: [[]])));
        sort($asns);

        return $asns;
    }

    /** @param  list<int>  $asns */
    public static function encode(array $asns): string
    {
        return implode("\n", $asns)."\n";
    }

    /** @return array<int, true> each ASN of a stored list, for a lookup by key */
    public static function decode(string $stored): array
    {
        $set = [];

        foreach (preg_split('/\R/', trim($stored)) ?: [] as $line) {
            if (ctype_digit($line)) {
                $set[(int) $line] = true;
            }
        }

        return $set;
    }
}
