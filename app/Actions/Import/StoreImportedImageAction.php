<?php

namespace App\Actions\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Support\Import\SafeImportHttpClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class StoreImportedImageAction
{
    public function __construct(private readonly SafeImportHttpClient $client) {}

    public function download(string $imageUrl): UploadedFile
    {
        $allowedMimes = (array) config('import.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);
        $maxBytes = (int) config('import.max_image_bytes', 8 * 1024 * 1024);

        $response = $this->client->get($imageUrl, $maxBytes);

        $rawContentType = $response->header('Content-Type');
        $host = parse_url($imageUrl, PHP_URL_HOST) ?? 'unknown';

        if (empty(trim($rawContentType))) {
            throw new ImportFetchException("No Content-Type header from {$host}");
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

        // The OS temp directory is self-cleaning by design; there's no
        // existing storage/app/tmp convention (or cleanup job) in this
        // codebase to place this under instead. Str::uuid() matches the
        // naming convention MediaPathGenerator already uses elsewhere, and
        // building the extension into the name up front avoids the
        // tempnam()+rename() dance tempnam() alone would otherwise need.
        $tmpPath = sys_get_temp_dir().'/rg_import_'.Str::uuid()->toString().'.'.$extension;

        if (File::put($tmpPath, $body) === false) {
            throw new ImportFetchException('Failed to write temporary file for imported image.');
        }

        return new UploadedFile(
            path: $tmpPath,
            originalName: basename(parse_url($imageUrl, PHP_URL_PATH) ?? 'imported.'.$extension),
            mimeType: $contentType,
            error: UPLOAD_ERR_OK,
            test: true,
        );
    }
}
