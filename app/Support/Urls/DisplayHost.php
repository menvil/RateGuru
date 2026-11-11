<?php

namespace App\Support\Urls;

/**
 * The site a URL belongs to, as a person reads it on a card: its host in
 * lower case, without the scheme, the path or a leading "www.", and with its
 * port only when it names one. A URL without a host reads as nothing.
 */
final class DisplayHost
{
    public static function of(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return '';
        }

        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $port = parse_url($url, PHP_URL_PORT);

        return $port !== null ? $host.':'.$port : $host;
    }
}
