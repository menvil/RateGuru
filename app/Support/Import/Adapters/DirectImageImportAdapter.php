<?php

namespace App\Support\Import\Adapters;

use App\Enums\ImportProvider;
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

        $response = $this->client->get($url, $maxImageBytes);

        $rawContentType = $response->header('Content-Type') ?? '';

        if (empty(trim($rawContentType))) {
            throw new ImportFetchException("No Content-Type header for URL: {$url}");
        }

        $contentType = strtolower(trim(explode(';', $rawContentType)[0]));

        if (! in_array($contentType, $allowedMimes, true)) {
            throw new ImportFetchException("Unsupported image MIME type '{$contentType}' for URL: {$url}");
        }

        if (strlen($response->body()) > $maxImageBytes) {
            throw ImportFetchException::responseTooLarge($url, $maxImageBytes);
        }

        return new ImportPreview(
            provider: ImportProvider::DirectImage,
            sourceUrl: $url,
            imageUrl: $url,
            title: $url,
        );
    }
}
