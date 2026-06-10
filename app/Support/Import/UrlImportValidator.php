<?php

namespace App\Support\Import;

use App\Exceptions\Import\UnsafeImportUrlException;

class UrlImportValidator
{
    private const PRIVATE_RANGES = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['127.0.0.0', '127.255.255.255'],
    ];

    public function validate(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            throw UnsafeImportUrlException::invalidUrl($url);
        }

        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, $this->allowedSchemes(), true)) {
            throw UnsafeImportUrlException::invalidScheme($scheme ?: '(none)');
        }

        $host = strtolower($parsed['host']);

        if ($host === 'localhost') {
            throw UnsafeImportUrlException::privateAddress($url);
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip !== false) {
            $this->assertPublicIp($ip, $url);
        } else {
            // Hostname — resolve DNS and validate every returned IP
            $resolved = $this->resolveHostname($host);

            if ($resolved === false) {
                throw UnsafeImportUrlException::invalidUrl($url);
            }

            foreach ($resolved as $resolvedIp) {
                $this->assertPublicIp($resolvedIp, $url);
            }
        }

        return $url;
    }

    private function allowedSchemes(): array
    {
        return (array) config('import.allowed_schemes', ['https']);
    }

    protected function resolveHostname(string $host): array|false
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || count($records) === 0) {
            return false;
        }

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return empty($ips) ? false : $ips;
    }

    private function assertPublicIp(string $ip, string $url): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if ($this->isPrivateIpv6($ip)) {
                throw UnsafeImportUrlException::privateAddress($url);
            }

            return;
        }

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

    private function isPrivateIpv6(string $ip): bool
    {
        if ($ip === '::1') {
            return true;
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        // fe80::/10 link-local
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return true;
        }

        // fc00::/7 unique-local (fc::/8 and fd::/8)
        if (($first & 0xFE) === 0xFC) {
            return true;
        }

        return false;
    }
}
