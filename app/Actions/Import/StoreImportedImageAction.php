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

        // SafeImportHttpClient defaults use max_html_bytes; for images we use a higher limit
        // We fetch with the safe client (SSRF + redirect protection) and validate size ourselves
        $response = $this->client->get($imageUrl);

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

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

        $tmpPath = tempnam(sys_get_temp_dir(), 'rg_import_').'.'.$extension;
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
