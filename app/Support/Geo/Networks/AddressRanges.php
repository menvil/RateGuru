<?php

namespace App\Support\Geo\Networks;

use Generator;

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
        return self::fromLines((function () use ($text): Generator {
            $offset = 0;
            $length = strlen($text);

            while ($offset < $length) {
                $end = strpos($text, "\n", $offset);
                $end = $end === false ? $length : $end;
                yield substr($text, $offset, $end - $offset);
                $offset = $end + 1;
            }
        })());
    }

    /**
     * The same, read from a file a line at a time: Apple's list is some
     * 280,000 lines, which split into one array at once would outgrow a
     * scheduled run's memory.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parseFile(string $path): array
    {
        return self::fromLines((function () use ($path): Generator {
            $file = @fopen($path, 'rb');

            if ($file === false) {
                return;
            }

            try {
                while (($line = fgets($file, 4096)) !== false) {
                    yield $line;
                }
            } finally {
                fclose($file);
            }
        })());
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
     * Each line's range kept as one 32-byte string — first then last address
     * — so a byte sort orders them by their first address, and some 280,000
     * of them fit in a few tens of MB; then merged where they touch or
     * overlap.
     *
     * @param  iterable<string>  $lines
     * @return list<array{0: string, 1: string}>
     */
    private static function fromLines(iterable $lines): array
    {
        $packed = [];
        $run = null;

        // A list in address order — Apple's mostly is — joins line after line into runs as it is read, so only the runs are kept.
        foreach ($lines as $line) {
            $cidr = trim(explode(',', $line, 2)[0]);
            $range = $cidr === '' ? null : self::range($cidr);

            if ($range === null) {
                continue;
            }

            if ($run !== null && strcmp($range[0], $run[0]) >= 0 && (strcmp($range[0], $run[1]) <= 0 || $range[0] === self::next($run[1]))) {
                $run[1] = strcmp($range[1], $run[1]) > 0 ? $range[1] : $run[1];

                continue;
            }

            if ($run !== null) {
                $packed[] = $run[0].$run[1];
            }

            $run = $range;
        }

        if ($run !== null) {
            $packed[] = $run[0].$run[1];
        }

        sort($packed, SORT_STRING);
        $merged = [];
        $top = -1;

        foreach ($packed as $record) {
            $first = substr($record, 0, 16);
            $last = substr($record, 16);

            if ($top >= 0 && (strcmp($first, $merged[$top][1]) <= 0 || $first === self::next($merged[$top][1]))) {
                if (strcmp($last, $merged[$top][1]) > 0) {
                    $merged[$top][1] = $last;
                }

                continue;
            }

            $merged[] = [$first, $last];
            $top++;
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
