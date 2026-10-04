<?php

namespace App\Support\Import;

use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\Dns\HostResolver;
use App\Support\Import\Net\PublicIpClassifier;
use App\Support\Observability\DomainLogger;

/**
 * The one place a user-controlled import URL is turned into a
 * ResolvedImportTarget: an IP that was resolved and validated for this exact
 * URL, right now. Every outbound import request — the initial one and every
 * redirect hop — must go through this before PinnedImportHttpTransport ever
 * opens a connection; the IP on the returned target is the only one the
 * transport is allowed to connect to.
 */
class UrlImportValidator
{
    public function __construct(
        private readonly HostResolver $hostResolver,
        private readonly PublicIpClassifier $classifier,
    ) {}

    public function validate(string $url, int $redirectHop = 0): ResolvedImportTarget
    {
        $this->assertNoControlCharacters($url, $redirectHop);

        ['parsed' => $parsed, 'scheme' => $scheme, 'host' => $host, 'port' => $port]
            = $this->assertSafeSyntax($url, $redirectHop);

        $ip = $this->resolveAndValidate($host, $url, $scheme, $port, $redirectHop);

        return new ResolvedImportTarget(
            url: $this->canonicalUrl($scheme, $host, $port, $parsed),
            scheme: $scheme,
            host: $host,
            port: $port,
            ip: $ip,
        );
    }

    /**
     * Everything about the URL that can be rejected from its own syntax
     * alone, before any DNS resolution or IP classification is even
     * attempted: scheme, host presence, userinfo, the bracketed-IPv6/
     * ambiguous-numeric-host forms, and the port allowlist.
     *
     * @return array{parsed: array<string, mixed>, scheme: string, host: string, port: int}
     */
    private function assertSafeSyntax(string $url, int $redirectHop): array
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            $this->logUnsafeBlocked($url, 'invalid_url', redirectHop: $redirectHop);
            throw UnsafeImportUrlException::invalidUrl($url);
        }

        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, $this->allowedSchemes(), true)) {
            $this->logUnsafeBlocked($url, 'invalid_scheme', $scheme, redirectHop: $redirectHop);
            throw UnsafeImportUrlException::invalidScheme($scheme ?: '(none)');
        }

        if (empty($parsed['host'])) {
            $this->logUnsafeBlocked($url, 'invalid_url', $scheme, redirectHop: $redirectHop);
            throw UnsafeImportUrlException::invalidUrl($url);
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            $this->logUnsafeBlocked($url, 'userinfo_forbidden', $scheme, redirectHop: $redirectHop);
            throw UnsafeImportUrlException::userinfoForbidden($url);
        }

        $host = $this->normalizeHost($parsed['host'], $url, $redirectHop);

        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, $this->allowedPorts(), true)) {
            $this->logUnsafeBlocked($url, 'port_forbidden', $scheme, $port, $redirectHop);
            throw UnsafeImportUrlException::portForbidden($port);
        }

        if ($this->isAmbiguousNumericHost($host)) {
            $this->logUnsafeBlocked($url, 'ambiguous_host', $scheme, $port, $redirectHop);
            throw UnsafeImportUrlException::ambiguousHost($url);
        }

        return ['parsed' => $parsed, 'scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    private function assertNoControlCharacters(string $url, int $redirectHop): void
    {
        // CR/LF/NUL/other control bytes anywhere in the URL — most commonly
        // an attempt at request/header smuggling once this string reaches an
        // HTTP client. Checked on the raw string, before parse_url() has a
        // chance to silently drop or mangle them.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            $this->logUnsafeBlocked($url, 'invalid_url', redirectHop: $redirectHop);
            throw UnsafeImportUrlException::invalidUrl($url);
        }
    }

    private function normalizeHost(string $rawHost, string $url, int $redirectHop): string
    {
        $host = strtolower($rawHost);

        $hasBracket = str_contains($host, '[') || str_contains($host, ']');

        if ($hasBracket) {
            if (! str_starts_with($host, '[') || ! str_ends_with($host, ']')) {
                $this->logUnsafeBlocked($url, 'invalid_url', redirectHop: $redirectHop);
                throw UnsafeImportUrlException::invalidUrl($url);
            }

            $inner = substr($host, 1, -1);

            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                $this->logUnsafeBlocked($url, 'invalid_url', redirectHop: $redirectHop);
                throw UnsafeImportUrlException::invalidUrl($url);
            }

            return $inner;
        }

        // A single trailing dot denotes an FQDN in DNS and is treated as
        // equivalent to the same host without it by resolvers and HTTP
        // clients alike. Stripped here, before any downstream check, so
        // every one of them (ambiguous-numeric-host detection, the
        // "localhost" special case, DNS resolution) sees the same
        // canonical form — otherwise a trailing dot lets an ambiguous host
        // slip past isAmbiguousNumericHost() entirely: "127.1." splits into
        // ['127', '1', ''], and that trailing empty label makes the
        // all-parts-numeric check bail out as "not a numeric host at all".
        if (str_ends_with($host, '.') && $host !== '.') {
            $host = substr($host, 0, -1);
        }

        return $host;
    }

    /**
     * Rejects host forms a resolver or the transport's own host parsing
     * might interpret as a raw IP even though they don't look like one to a
     * naive dotted-quad check — a decimal 32-bit integer, a hex integer, a
     * short-form dotted host that silently absorbs missing octets, or a
     * dotted octet with a leading zero (octal in some parsers, decimal in
     * others — a classic decoder-differential SSRF bypass). Deliberately
     * rejected outright rather than "supported" per any one interpretation.
     */
    private function isAmbiguousNumericHost(string $host): bool
    {
        if (preg_match('/^\d+$/', $host) === 1) {
            return true;
        }

        if (preg_match('/^0x[0-9a-f]+$/i', $host) === 1) {
            return true;
        }

        $parts = explode('.', $host);

        if (count($parts) < 2 || count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^(0x[0-9a-f]+|\d+)$/i', $part) !== 1) {
                // Not a purely numeric dotted host at all — an ordinary
                // hostname label, nothing ambiguous to reject.
                return false;
            }
        }

        if (count($parts) !== 4) {
            return true;
        }

        foreach ($parts as $part) {
            if (preg_match('/^0x/i', $part) === 1) {
                return true;
            }

            if (strlen($part) > 1 && $part[0] === '0') {
                return true;
            }
        }

        return false;
    }

    private function resolveAndValidate(string $host, string $url, string $scheme, int $port, int $redirectHop): string
    {
        if ($host === 'localhost') {
            $this->logUnsafeBlocked($url, 'private_address', $scheme, $port, $redirectHop);
            throw UnsafeImportUrlException::privateAddress($url);
        }

        $literalIp = filter_var($host, FILTER_VALIDATE_IP);

        if ($literalIp !== false) {
            if (! $this->classifier->isPublic($literalIp)) {
                $this->logUnsafeBlocked($url, 'private_address', $scheme, $port, $redirectHop);
                throw UnsafeImportUrlException::privateAddress($url);
            }

            return $literalIp;
        }

        $resolved = array_values(array_unique($this->hostResolver->resolve($host)));

        if ($resolved === []) {
            $this->logUnsafeBlocked($url, 'dns_resolution_failed', $scheme, $port, $redirectHop);
            throw UnsafeImportUrlException::dnsResolutionFailed($url);
        }

        $publicIps = [];

        foreach ($resolved as $candidate) {
            if (! $this->classifier->isPublic($candidate)) {
                // A mixed public+private answer set rejects the whole
                // hostname — an attacker controlling DNS could otherwise
                // alternate which record actually wins the real connection.
                $this->logUnsafeBlocked($url, 'private_address', $scheme, $port, $redirectHop);
                throw UnsafeImportUrlException::privateAddress($url);
            }

            $publicIps[] = $candidate;
        }

        // Deterministic choice, not a fresh/uncontrolled lookup: the first
        // IP out of the already-resolved-and-validated set.
        return $publicIps[0];
    }

    private function canonicalUrl(string $scheme, string $host, int $port, array $parsed): string
    {
        $displayHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        $url = "{$scheme}://{$displayHost}";

        if ($port !== $defaultPort) {
            $url .= ':'.$port;
        }

        $url .= $parsed['path'] ?? '';

        if (isset($parsed['query'])) {
            $url .= '?'.$parsed['query'];
        }

        // Fragment deliberately dropped — it's never sent to the server and
        // has no business surviving into a transport-layer URL.
        return $url;
    }

    private function allowedSchemes(): array
    {
        return (array) config('import.allowed_schemes', ['https']);
    }

    private function allowedPorts(): array
    {
        return array_map('intval', (array) config('import.allowed_ports', [80, 443]));
    }

    private function logUnsafeBlocked(string $url, string $reason, ?string $scheme = null, ?int $port = null, int $redirectHop = 0): void
    {
        $parsed = parse_url($url);

        $context = array_filter([
            'source_host' => is_array($parsed) ? ($parsed['host'] ?? 'unknown') : 'unknown',
            'reason' => $reason,
            'scheme' => $scheme,
            'port' => $port,
            'redirect_hop' => $redirectHop > 0 ? $redirectHop : null,
        ], fn ($value) => $value !== null);

        app(DomainLogger::class)->warning('url_import.unsafe_url_blocked', $context);
        app(DomainLogger::class)->security('security.unsafe_url_blocked', $context);
    }
}
