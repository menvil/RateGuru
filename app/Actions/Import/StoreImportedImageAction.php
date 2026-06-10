<?php

namespace App\Actions\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Support\Import\SafeImportHttpClient;
use Illuminate\Http\UploadedFile;

class StoreImportedImageAction
{
    public function __construct(private readonly SafeImportHttpClient $client) {}

    public function download(string $imageUrl): UploadedFile
    {
        $allowedMimes = (array) config('import.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);
        $maxBytes = (int) config('import.max_image_bytes', 8 * 1024 * 1024);

        $response = $this->client->get($imageUrl, $maxBytes);

        $rawContentType = $response->header('Content-Type') ?? '';

        if (empty(trim($rawContentType))) {
            throw new ImportFetchException("No Content-Type header for URL: {$imageUrl}");
        }

        $contentType = strtolower(trim(explode(';', $rawContentType)[0]));

        if (! in_array($contentType, $allowedMimes, true)) {
            throw new ImportFetchException("Unsupported MIME type '{$contentType}' for imported image.");
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw ImportFetchException::responseTooLarge($imageUrl, $maxBytes);
        }

        $extension = match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $baseTmpPath = tempnam(sys_get_temp_dir(), 'rg_import_');
        $tmpPath = $baseTmpPath.'.'.$extension;
        rename($baseTmpPath, $tmpPath);
        file_put_contents($tmpPath, $body);

        return new UploadedFile(
            path: $tmpPath,
            originalName: basename(parse_url($imageUrl, PHP_URL_PATH) ?? 'imported.'.$extension),
            mimeType: $contentType,
            error: UPLOAD_ERR_OK,
            test: true,
        );
    }
}
