<?php

namespace App\Support\Import\Adapters;

use App\Exceptions\Import\ImportFetchException;
use App\Support\Import\ImportPreview;
use App\Support\Import\SafeImportHttpClient;

class DirectImageImportAdapter
{
    public function __construct(private readonly SafeImportHttpClient $client) {}

    public function preview(string $url): ImportPreview
    {
        $maxImageBytes = (int) config('import.max_image_bytes', 8 * 1024 * 1024);
        $allowedMimes = (array) config('import.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        $response = $this->client->get($url);

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if (! in_array($contentType, $allowedMimes, true)) {
            throw new ImportFetchException("Unsupported image MIME type '{$contentType}' for URL: {$url}");
        }

        if (strlen($response->body()) > $maxImageBytes) {
            throw ImportFetchException::responseTooLarge($url, $maxImageBytes);
        }

        return new ImportPreview(
            provider: 'direct_image',
            sourceUrl: $url,
            imageUrl: $url,
        );
    }
}
