<?php

namespace App\Support\Geo\Networks;

/**
 * A set of IP addresses kept as sorted, merged ranges in a compact file: each
 * range is two 16-byte addresses, its first and its last, IPv4 written as an
 * IPv4-mapped IPv6 address (::ffff:a.b.c.d), so one comparison of bytes
 * orders every address. A lookup reads the file with seeks — a binary search,
 * some twenty reads of 32 bytes — never loading it whole.
 *
 * Built from CIDR ranges at the start of each line (Apple's list of the
 * addresses iCloud Private Relay sends traffic from: "172.224.226.0/27,GB,…").
 */
final class AddressRanges
{
    public const int RECORD_BYTES = 32;

    /**
     * The first CIDR range of each line, as [first, last] 16-byte addresses,
     * sorted and merged where they touch or overlap. Lines without one are
     * skipped.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parse(string $text): array
    {
        $ranges = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $cidr = trim(explode(',', $line, 2)[0]);
            $range = $cidr === '' ? null : self::range($cidr);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return self::merge($ranges);
    }

    /**
     * A CIDR range as its first and last address, 16 bytes each; null for
     * anything that is not one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function range(string $cidr): ?array
    {
        $parts = explode('/', $cidr);

        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return null;
        }

        $packed = @inet_pton($parts[0]);

        if ($packed === false) {
            return null;
        }

        $v4 = strlen($packed) === 4;
        $prefix = (int) $parts[1] + ($v4 ? 96 : 0);

        if ($prefix > 128 || ((int) $parts[1] > 32 && $v4)) {
            return null;
        }

        $address = self::sixteen($packed);
        $first = '';
        $last = '';

        for ($byte = 0; $byte < 16; $byte++) {
            $kept = max(0, min(8, $prefix - $byte * 8));
            $mask = $kept === 0 ? 0 : (0xFF << (8 - $kept)) & 0xFF;
            $value = ord($address[$byte]);
            $first .= chr($value & $mask);
            $last .= chr(($value & $mask) | (~$mask & 0xFF));
        }

        return [$first, $last];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $ranges
     * @return list<array{0: string, 1: string}>
     */
    public static function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        $merged = [];

        foreach ($ranges as $range) {
            $top = count($merged) - 1;

            if ($top >= 0 && (strcmp($range[0], $merged[$top][1]) <= 0 || $range[0] === self::next($merged[$top][1]))) {
                if (strcmp($range[1], $merged[$top][1]) > 0) {
                    $merged[$top][1] = $range[1];
                }

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /** @param  list<array{0: string, 1: string}>  $ranges */
    public static function encode(array $ranges): string
    {
        return implode('', array_map(fn (array $range): string => $range[0].$range[1], $ranges));
    }

    /**
     * Whether a file of ranges holds an address: false for an address that
     * is not one, and for a file that is missing or not a whole number of
     * ranges.
     */
    public static function contains(string $path, string $ip): bool
    {
        $packed = @inet_pton($ip);
        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($packed === false || $size === 0 || $size % self::RECORD_BYTES !== 0) {
            return false;
        }

        $address = self::sixteen($packed);
        $file = @fopen($path, 'rb');

        if ($file === false) {
            return false;
        }

        try {
            $low = 0;
            $high = intdiv($size, self::RECORD_BYTES) - 1;

            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                fseek($file, $middle * self::RECORD_BYTES);
                $record = (string) fread($file, self::RECORD_BYTES);

                if (strlen($record) !== self::RECORD_BYTES) {
                    return false;
                }

                if (strcmp($address, substr($record, 0, 16)) < 0) {
                    $high = $middle - 1;
                } elseif (strcmp($address, substr($record, 16)) > 0) {
                    $low = $middle + 1;
                } else {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($file);
        }
    }

    /** An address of 4 or 16 bytes as 16: IPv4 mapped into IPv6. */
    private static function sixteen(string $packed): string
    {
        return strlen($packed) === 4 ? str_repeat("\0", 10)."\xff\xff".$packed : $packed;
    }

    /** The address after this one; the last address stays itself. */
    private static function next(string $address): string
    {
        for ($byte = 15; $byte >= 0; $byte--) {
            if ($address[$byte] !== "\xff") {
                return substr($address, 0, $byte).chr(ord($address[$byte]) + 1).str_repeat("\0", 15 - $byte);
            }
        }

        return $address;
    }
}
