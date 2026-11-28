<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportDomainBlockedException;
use App\Support\Settings\ProjectSettingsManager;

/**
 * The sites a link may not be imported from, named by the administrators in
 * Project settings: a domain blocks itself and every host under it, so
 * example.com blocks www.example.com and cdn.example.com too.
 *
 * UrlImportValidator asks it of every import request — the link itself and
 * every redirect hop — so nothing is fetched from a blocked site, whatever
 * path the link takes. The author is told the site is not supported.
 */
final class BlockedImportDomains
{
    /** The most domains the list keeps. */
    public const int MAX = 500;

    public function __construct(private readonly ProjectSettingsManager $settings) {}

    /** @throws ImportDomainBlockedException when the link's host is on a blocked site */
    public function assertAllowed(string $url): void
    {
        $host = self::hostOf($url);

        if ($host !== null && $this->blocks($host)) {
            throw ImportDomainBlockedException::make($host);
        }
    }

    public function blocks(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');

        foreach ($this->settings->current()->blockedImportDomains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The domains an administrator typed, one per line or comma-separated, as
     * the list keeps them: lowercase, without a scheme, a path or a leading
     * "www.", each once. What is not a domain is returned apart, to be named
     * back to them.
     *
     * @return array{domains: list<string>, invalid: list<string>}
     */
    public static function parse(string $text): array
    {
        $domains = [];
        $invalid = [];

        foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $domain = self::domainOf($entry);

            if ($domain === null) {
                $invalid[] = $entry;

                continue;
            }

            $domains[$domain] = true;
        }

        return ['domains' => array_keys($domains), 'invalid' => array_values(array_unique($invalid))];
    }

    private static function domainOf(string $entry): ?string
    {
        $entry = strtolower(trim($entry));
        $host = str_contains($entry, '://') ? parse_url($entry, PHP_URL_HOST) : explode('/', $entry, 2)[0];

        if (! is_string($host)) {
            return null;
        }

        $host = rtrim($host, '.');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        // A name with at least one dot, of the letters, digits and hyphens a
        // host is made of — a top-level domain alone would block a country.
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
