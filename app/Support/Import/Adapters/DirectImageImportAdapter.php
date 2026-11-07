<?php

namespace App\Support\Import\Adapters;

use App\Enums\ImportProvider;
use App\Exceptions\Import\ImportFetchException;
use App\Support\Import\ImportedImageLimit;
use App\Support\Import\ImportPreview;
use App\Support\Import\SafeImportHttpClient;

class DirectImageImportAdapter
{
    public function __construct(private readonly SafeImportHttpClient $client) {}

    public function preview(string $url): ImportPreview
    {
        $allowedMimes = (array) config('import.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        $response = $this->client->get($url, ImportedImageLimit::bytes());

        $rawContentType = $response->header('Content-Type') ?? '';
        $host = parse_url($url, PHP_URL_HOST) ?? 'unknown';

        if (empty(trim($rawContentType))) {
            throw new ImportFetchException("No Content-Type header from {$host}");
        }

        $contentType = strtolower(trim(explode(';', $rawContentType)[0]));

        if (! in_array($contentType, $allowedMimes, true)) {
            throw new ImportFetchException("Unsupported image MIME type '{$contentType}' from {$host}");
        }

        return new ImportPreview(
            provider: ImportProvider::DirectImage,
            sourceUrl: $url,
            imageUrl: $url,
            title: $url,
        );
    }
}
