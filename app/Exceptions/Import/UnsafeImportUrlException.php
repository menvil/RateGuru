<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * Every internal reason a URL/host was rejected before any connection was
 * ever attempted. All of these surface to the end user as the same generic
 * "unsafe_url" message (see ImportUrlForm) — the distinct factories exist so
 * logs/tests can tell exactly which check fired, not to expose more detail
 * to the client.
 */
class UnsafeImportUrlException extends RuntimeException
{
    public static function invalidScheme(string $scheme): self
    {
        return new self("URL scheme '{$scheme}' is not allowed for import.");
    }

    public static function privateAddress(string $url): self
    {
        return new self('URL resolves to a private or reserved address and cannot be fetched.');
    }

    public static function invalidUrl(string $url): self
    {
        return new self("The provided URL is not valid: '{$url}'.");
    }

    public static function userinfoForbidden(string $url): self
    {
        return new self('URL must not contain userinfo (credentials) and cannot be fetched.');
    }

    public static function portForbidden(int $port): self
    {
        return new self("Port {$port} is not allowed for import.");
    }

    public static function ambiguousHost(string $url): self
    {
        return new self('The provided host is an ambiguous numeric form and cannot be validated safely.');
    }

    public static function dnsResolutionFailed(string $url): self
    {
        return new self('The host could not be resolved.');
    }
}
