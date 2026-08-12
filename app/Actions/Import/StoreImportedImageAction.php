<?php

namespace App\Actions\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Services\Media\ImageIngestPolicy;
use App\Support\Import\SafeImportHttpClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class StoreImportedImageAction
{
    public function __construct(private readonly SafeImportHttpClient $client) {}

    public function download(string $imageUrl): UploadedFile
    {
        $allowedMimes = (array) config('import.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        // Never fetch more than ImageIngestor would accept anyway — a
        // separate, larger import-layer limit would let this download a
        // payload ImageIngestor immediately rejects, wasting the bandwidth
        // and time the cap exists to save in the first place.
        $maxBytes = min(
            (int) config('import.max_image_bytes', 8 * 1024 * 1024),
            ImageIngestPolicy::fromConfig()->maxBytes,
        );

        $response = $this->client->get($imageUrl, $maxBytes);

        $rawContentType = $response->header('Content-Type') ?? '';
        $host = parse_url($imageUrl, PHP_URL_HOST) ?? 'unknown';

        if (empty(trim($rawContentType))) {
            throw new ImportFetchException("No Content-Type header from {$host}");
        }

        $contentType = strtolower(trim(explode(';', $rawContentType)[0]));

        if (! in_array($contentType, $allowedMimes, true)) {
            throw new ImportFetchException("Unsupported MIME type '{$contentType}' for imported image.");
        }

        $body = $response->body;

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

        // Created empty and locked down to 0600 *before* any body byte is
        // written — the previous order (write, then chmod) left the file
        // briefly world-readable, with the downloaded content already in
        // it, in the shared OS temp directory. 'x' mode both creates the
        // file and fails atomically if something already exists at this
        // (UUID-derived, so practically never colliding) path.
        $handle = @fopen($tmpPath, 'x');

        if ($handle === false) {
            throw new ImportFetchException('Failed to create temporary file for imported image.');
        }

        if (! @chmod($tmpPath, 0600) || (@fileperms($tmpPath) & 0777) !== 0600) {
            fclose($handle);
            $this->deleteQuietly($tmpPath);

            throw new ImportFetchException('Failed to secure temporary file permissions for imported image.');
        }

        // fwrite() is documented to not guarantee writing the whole string
        // in one call — looping on the actually-written offset is the
        // PHP-manual-recommended way to avoid silently truncating a large
        // (up to several MB) downloaded body into an incomplete file.
        $length = strlen($body);
        $written = 0;

        while ($written < $length) {
            $bytesWritten = fwrite($handle, substr($body, $written));

            if ($bytesWritten === false || $bytesWritten === 0) {
                fclose($handle);
                $this->deleteQuietly($tmpPath);

                throw new ImportFetchException('Failed to write temporary file for imported image.');
            }

            $written += $bytesWritten;
        }

        fclose($handle);

        return new UploadedFile(
            path: $tmpPath,
            // A sanitized display hint only, never trusted for anything
            // else — the URL's own path basename with its extension
            // stripped and replaced by the canonical one from the
            // validated Content-Type above (the URL's claimed extension,
            // if any, could easily disagree with what the bytes actually
            // are).
            originalName: $this->originalNameHint($imageUrl, $extension),
            mimeType: $contentType,
            error: UPLOAD_ERR_OK,
            test: true,
        );
    }

    private function originalNameHint(string $imageUrl, string $extension): string
    {
        $baseName = basename(parse_url($imageUrl, PHP_URL_PATH) ?? '');
        $nameWithoutExtension = $baseName !== '' ? pathinfo($baseName, PATHINFO_FILENAME) : '';

        return ($nameWithoutExtension !== '' ? $nameWithoutExtension : 'imported').'.'.$extension;
    }

    /**
     * Removes the temp file this action created for a UrlImport-sourced
     * image. The caller (UploadPostForm) owns calling this from a finally
     * block once it's done with the UploadedFile — download() itself can't
     * clean up on success, since the file must still exist for whatever
     * reads it afterward (ImageUploadStorer, by way of ImageIngestor).
     * Best-effort and silent: a cleanup failure must never turn a
     * successful (or already-failed) post submission into an error.
     */
    public function cleanup(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if ($path !== false) {
            $this->deleteQuietly($path);
        }
    }

    private function deleteQuietly(string $path): void
    {
        try {
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
            // Best-effort — the OS temp directory is self-cleaning anyway.
        }
    }
}
