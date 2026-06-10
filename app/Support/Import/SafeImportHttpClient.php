<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SafeImportHttpClient
{
    public function __construct(private readonly UrlImportValidator $validator) {}

    public function get(string $url): Response
    {
        $this->validator->validate($url);

        $timeout = (int) config('import.timeout_seconds', 5);
        $connectTimeout = (int) config('import.connect_timeout_seconds', 2);
        $maxRedirects = (int) config('import.max_redirects', 3);
        $maxBytes = (int) config('import.max_html_bytes', 1024 * 1024);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->maxRedirects($maxRedirects)
                ->get($url);
        } catch (ConnectionException $e) {
            throw ImportFetchException::connectionError($url, $e->getMessage());
        }

        if ($response->failed()) {
            throw ImportFetchException::requestFailed($url, $response->status());
        }

        if (strlen($response->body()) > $maxBytes) {
            throw ImportFetchException::responseTooLarge($url, $maxBytes);
        }

        return $response;
    }
}
