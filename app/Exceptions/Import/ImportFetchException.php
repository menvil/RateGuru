<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * Every internal reason a fetch failed once a connection was actually
 * attempted (as opposed to UnsafeImportUrlException, which is thrown before
 * any connection is attempted at all). All of these surface to the end user
 * as the same generic "fetch_failed" message (see ImportUrlForm) — the
 * distinct factories exist so logs/tests can tell exactly which failure mode
 * fired, not to expose transport internals to the client.
 */
class ImportFetchException extends RuntimeException
{
    public static function requestFailed(string $url, int $status): self
    {
        return new self("Import request to '".self::sanitizeUrl($url)."' failed with status {$status}.");
    }

    /**
     * Covers the whole family of low-level transport failures — connect
     * timeout, read timeout, connection reset, TLS failure — as one bucket.
     * Laravel's own Http client already collapses all of these into a single
     * Illuminate\Http\Client\ConnectionException by the time it reaches
     * PinnedImportHttpTransport's catch block, so splitting them further
     * here would mean parsing exception message text, which is brittle.
     *
     * Deliberately never chains the raw transport exception as $previous:
     * that exception's own message is entirely outside our control (it's
     * whatever curl/Guzzle produced, which commonly embeds the full,
     * unsanitized request URL verbatim — including any query-string
     * tokens). Laravel's default exception logging walks the full
     * previous-exception chain and prints every message in it, so
     * chaining it here — even wrapped — would be a second leak path
     * alongside this exception's own (sanitized) message. Callers that
     * want to preserve "what kind of transport failure was this" for
     * diagnostics do so via their own structured logging instead (see
     * PinnedImportHttpTransport), which only ever carries the host and
     * the failing exception's class name.
     */
    public static function connectionError(string $url, string $reason): self
    {
        $sanitizedUrl = self::sanitizeUrl($url);
        $safeReason = self::sanitizeReason($reason, $sanitizedUrl);

        return new self("Could not connect to '{$sanitizedUrl}': {$safeReason}");
    }

    /**
     * curl's own error messages commonly embed the raw request URL
     * verbatim ("cURL error 6: Could not resolve host: x for
     * https://x/page?token=..."), which would otherwise smuggle an
     * unsanitized, credential-bearing URL past sanitizeUrl(). Any
     * embedded URL is replaced with the already-sanitized one.
     */
    private static function sanitizeReason(string $reason, string $sanitizedUrl): string
    {
        return preg_replace('/https?:\/\/\S+/i', $sanitizedUrl, $reason) ?? '(reason redacted)';
    }

    public static function responseTooLarge(string $url, int $maxBytes): self
    {
        $kb = (int) ($maxBytes / 1024);

        return new self("Response from '".self::sanitizeUrl($url)."' exceeds the {$kb} KB limit.");
    }

    public static function tooManyRedirects(string $url): self
    {
        return new self("Import request to '".self::sanitizeUrl($url)."' exceeded the maximum number of redirects.");
    }

    public static function missingRedirectLocation(string $url): self
    {
        return new self("Import request to '".self::sanitizeUrl($url)."' redirected without a Location header.");
    }

    private static function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (empty($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $params);

        $sensitive = ['token', 'key', 'auth', 'password', 'secret'];

        foreach ($params as $k => $v) {
            foreach ($sensitive as $pat) {
                if (str_contains(strtolower((string) $k), $pat)) {
                    $params[$k] = '[REDACTED]';
                    break;
                }
            }
        }

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '')
            .(isset($parsed['port']) ? ':'.$parsed['port'] : '')
            .($parsed['path'] ?? '')
            .'?'.http_build_query($params);
    }
}
