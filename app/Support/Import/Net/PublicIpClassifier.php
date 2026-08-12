<?php

namespace App\Support\Import\Net;

/**
 * Classifies a resolved IP address as public/routable or not. Every check is
 * numeric/binary CIDR containment (ip2long()+bitmask for IPv4, inet_pton()
 * byte comparison for IPv6) — never a string-prefix check, which is fragile
 * against non-canonical representations (e.g. "::ffff:0:0" vs
 * "0:0:0:0:0:ffff:0:0" are the same address but don't share a string prefix).
 */
final class PublicIpClassifier
{
    /**
     * @var list<array{0: string, 1: int}>
     */
    private const IPV4_BLOCKED_CIDRS = [
        ['0.0.0.0', 8],       // "this" network
        ['10.0.0.0', 8],      // RFC1918 private
        ['100.64.0.0', 10],   // carrier-grade NAT (RFC6598)
        ['127.0.0.0', 8],     // loopback
        ['169.254.0.0', 16],  // link-local, incl. cloud metadata endpoints
        ['172.16.0.0', 12],   // RFC1918 private
        ['192.0.0.0', 24],    // IETF protocol assignments
        ['192.0.2.0', 24],    // TEST-NET-1 documentation
        ['192.168.0.0', 16],  // RFC1918 private
        ['198.18.0.0', 15],   // benchmarking (RFC2544)
        ['198.51.100.0', 24], // TEST-NET-2 documentation
        ['203.0.113.0', 24],  // TEST-NET-3 documentation
        ['224.0.0.0', 4],     // multicast
        ['240.0.0.0', 4],     // reserved
    ];

    /**
     * @var list<array{0: string, 1: int}>
     */
    private const IPV6_BLOCKED_CIDRS = [
        ['::', 128],        // unspecified
        ['::1', 128],       // loopback
        ['fc00::', 7],      // unique local (fc00::/8 + fd00::/8)
        ['fe80::', 10],     // link-local
        ['ff00::', 8],      // multicast
        ['2001:db8::', 32], // documentation (RFC3849)
    ];

    public function isPublic(string $ip): bool
    {
        $ipv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($ipv4 !== false) {
            return ! $this->matchesIpv4Cidrs($ipv4, self::IPV4_BLOCKED_CIDRS);
        }

        $ipv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if ($ipv6 === false) {
            // Not a recognizable IP at all — never treated as "public".
            return false;
        }

        $embeddedIpv4 = $this->extractIpv4Mapped($ipv6);

        if ($embeddedIpv4 !== null) {
            return ! $this->matchesIpv4Cidrs($embeddedIpv4, self::IPV4_BLOCKED_CIDRS);
        }

        return ! $this->matchesIpv6Cidrs($ipv6, self::IPV6_BLOCKED_CIDRS);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $cidrs
     */
    private function matchesIpv4Cidrs(string $ip, array $cidrs): bool
    {
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        foreach ($cidrs as [$base, $prefix]) {
            $baseLong = ip2long($base);
            $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;

            if (($ipLong & $mask) === ($baseLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $cidrs
     */
    private function matchesIpv6Cidrs(string $ip, array $cidrs): bool
    {
        $ipBin = inet_pton($ip);

        if ($ipBin === false) {
            return false;
        }

        foreach ($cidrs as [$base, $prefix]) {
            $baseBin = inet_pton($base);

            if ($baseBin !== false && $this->ipv6BinaryInCidr($ipBin, $baseBin, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function ipv6BinaryInCidr(string $ipBin, string $baseBin, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($baseBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($baseBin[$fullBytes]) & $mask);
    }

    /**
     * "::ffff:a.b.c.d" (RFC4291 IPv4-mapped): the first 80 bits are zero, the
     * next 16 bits are all-ones, and the last 32 bits are the embedded IPv4
     * address — which must be classified as if it were fetched directly, so
     * e.g. "::ffff:169.254.169.254" is blocked exactly like the bare
     * metadata-endpoint address is.
     */
    private function extractIpv4Mapped(string $ipv6): ?string
    {
        $packed = inet_pton($ipv6);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 10) !== str_repeat("\x00", 10) || substr($packed, 10, 2) !== "\xff\xff") {
            return null;
        }

        return inet_ntop(substr($packed, 12, 4)) ?: null;
    }
}
