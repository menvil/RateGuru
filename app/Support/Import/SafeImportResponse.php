<?php

namespace App\Support\Import;

/**
 * SafeImportHttpClient's public return type — a narrow, immutable contract
 * so nothing downstream can reach past it into a generic HTTP client
 * response and use it unsafely (e.g. re-fetching a Location header itself,
 * bypassing this boundary entirely).
 */
final readonly class SafeImportResponse
{
    /**
     * @param  array<string, string>  $headers  lower-cased header name => single value
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $finalUrl,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
