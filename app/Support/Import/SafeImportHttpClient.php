<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SafeImportHttpClient
{
    public function __construct(private readonly UrlImportValidator $validator) {}

    public function get(string $url, ?int $maxBytes = null): Response
    {
        $this->validator->validate($url);

        $timeout = (int) config('import.timeout_seconds');
        $connectTimeout = (int) config('import.connect_timeout_seconds');
        $maxRedirects = (int) config('import.max_redirects');
        $maxBytes = $maxBytes ?? (int) config('import.max_html_bytes');

        $hops = 0;
        $currentUrl = $url;

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withoutRedirecting()
                ->get($currentUrl);
        } catch (ConnectionException $e) {
            throw ImportFetchException::connectionError($url, $e->getMessage());
        }

        while (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            if ($hops >= $maxRedirects) {
                throw ImportFetchException::connectionError($url, 'Too many redirects');
            }

            $location = $response->header('Location');

            if (empty($location)) {
                throw ImportFetchException::connectionError($url, 'Redirect without Location header');
            }

            $currentUrl = $this->resolveRedirectUrl($location, $currentUrl);
            $this->validator->validate($currentUrl);

            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->withoutRedirecting()
                    ->get($currentUrl);
            } catch (ConnectionException $e) {
                throw ImportFetchException::connectionError($url, $e->getMessage());
            }

            $hops++;
        }

        if ($response->failed()) {
            throw ImportFetchException::requestFailed($url, $response->status());
        }

        $contentLength = (int) $response->header('Content-Length');

        if ($contentLength > 0 && $contentLength > $maxBytes) {
            throw ImportFetchException::responseTooLarge($url, $maxBytes);
        }

        if (strlen($response->body()) > $maxBytes) {
            throw ImportFetchException::responseTooLarge($url, $maxBytes);
        }

        return $response;
    }

    private function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parsed = parse_url($currentUrl);
        $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $base .= ':'.$parsed['port'];
        }

        if (str_starts_with($location, '//')) {
            return ($parsed['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $base.$location;
        }

        $path = rtrim(dirname($parsed['path'] ?? ''), '/');

        return $base.$path.'/'.$location;
    }
}
