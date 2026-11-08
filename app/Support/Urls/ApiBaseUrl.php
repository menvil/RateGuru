<?php

namespace App\Support\Urls;

/**
 * Whether a configured API base URL may be sent a bearer key: https always,
 * plain http only to this machine's loopback — a local fake or proxy in
 * development — and never across a network, where the key and the request
 * would travel in cleartext.
 */
final class ApiBaseUrl
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function carriesKeySafely(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http'
            && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::LOOPBACK_HOSTS, true);
    }
}
