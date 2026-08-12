<?php

namespace App\Support\Import;

/**
 * The raw result of a single hop fetched by an ImportHttpTransport. Redirects
 * are not followed here — SafeImportHttpClient inspects the status/headers
 * of one of these and decides whether to resolve, validate, and pin another
 * hop itself.
 */
final readonly class ImportTransportResponse
{
    /**
     * @param  array<string, string>  $headers  lower-cased header name => single value
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
