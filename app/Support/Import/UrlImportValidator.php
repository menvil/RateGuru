<?php

namespace App\Support\Import;

use App\Exceptions\Import\UnsafeImportUrlException;

class UrlImportValidator
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // Private IPv4 CIDR ranges that must be blocked (SSRF protection)
    private const PRIVATE_RANGES = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['169.254.0.0', '169.254.255.255'], // link-local / AWS metadata
        ['127.0.0.0', '127.255.255.255'],   // loopback
    ];

    public function validate(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            throw UnsafeImportUrlException::invalidUrl($url);
        }

        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw UnsafeImportUrlException::invalidScheme($scheme ?: '(none)');
        }

        $host = strtolower($parsed['host']);

        if ($host === 'localhost') {
            throw UnsafeImportUrlException::privateAddress($url);
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip !== false) {
            $this->assertPublicIp($ip, $url);
        }

        return $url;
    }

    private function assertPublicIp(string $ip, string $url): void
    {
        // Reject IPv6 loopback
        if ($ip === '::1') {
            throw UnsafeImportUrlException::privateAddress($url);
        }

        // Only check IPv4 private ranges for IPv4 addresses
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return;
        }

        $long = ip2long($ip);

        foreach (self::PRIVATE_RANGES as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                throw UnsafeImportUrlException::privateAddress($url);
            }
        }
    }
}
