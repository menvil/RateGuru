<?php

namespace App\Exceptions\Import;

use RuntimeException;

class ImportFetchException extends RuntimeException
{
    public static function requestFailed(string $url, int $status): self
    {
        return new self("Import request to '".self::sanitizeUrl($url)."' failed with status {$status}.");
    }

    public static function connectionError(string $url, string $reason): self
    {
        return new self("Could not connect to '".self::sanitizeUrl($url)."': {$reason}");
    }

    public static function responseTooLarge(string $url, int $maxBytes): self
    {
        $kb = (int) ($maxBytes / 1024);

        return new self("Response from '".self::sanitizeUrl($url)."' exceeds the {$kb} KB limit.");
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
