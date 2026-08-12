<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    bindFakeHostResolver();
});

it('downloads and stores imported image as uploaded file', function () {
    Storage::fake('public');

    // Minimal 1×1 white JPEG bytes
    $jpegBytes = base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAARC'.
        'AABAAEBASISAAREBAREF/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAA'.
        'AAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJQAAB//2Q=='
    );

    Http::fake([
        'example.com/image.jpg' => Http::response($jpegBytes, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $file = app(StoreImportedImageAction::class)->download('https://example.com/image.jpg');

    expect($file)->toBeInstanceOf(UploadedFile::class);
    expect($file->getClientOriginalName())->toContain('image');
});

it('rejects unsafe image url', function () {
    app(StoreImportedImageAction::class)->download('http://192.168.1.1/image.jpg');
})->throws(UnsafeImportUrlException::class);

it('rejects image exceeding max size', function () {
    $oversized = str_repeat('x', config('import.max_image_bytes') + 1);

    Http::fake([
        'example.com/big.jpg' => Http::response($oversized, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    app(StoreImportedImageAction::class)->download('https://example.com/big.jpg');
})->throws(ImportFetchException::class);

it('rejects non-image content type', function () {
    Http::fake([
        'example.com/file.html' => Http::response('<html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    app(StoreImportedImageAction::class)->download('https://example.com/file.html');
})->throws(ImportFetchException::class);

it('rejects a response with no content-type header at all, without a null-coercion warning', function () {
    Http::fake([
        // No Content-Type key at all -- header('Content-Type') returns
        // null here, distinct from an empty-string header value.
        'example.com/image.jpg' => Http::response('bytes', 200, []),
    ]);

    app(StoreImportedImageAction::class)->download('https://example.com/image.jpg');
})->throws(ImportFetchException::class);

it('writes the temp file with private (non-world-readable) permissions', function () {
    Http::fake([
        'example.com/image.jpg' => Http::response('bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $file = app(StoreImportedImageAction::class)->download('https://example.com/image.jpg');

    $permissions = fileperms($file->getRealPath()) & 0777;

    expect($permissions)->toBe(0600);
});

it('deletes the temp file when cleanup() is called', function () {
    Http::fake([
        'example.com/image.jpg' => Http::response('bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $file = app(StoreImportedImageAction::class)->download('https://example.com/image.jpg');
    $path = $file->getRealPath();

    expect($path)->not->toBeFalse();
    expect(file_exists($path))->toBeTrue();

    app(StoreImportedImageAction::class)->cleanup($file);

    expect(file_exists($path))->toBeFalse();
});

it('does not throw when cleanup() is called on a file that no longer exists', function () {
    Http::fake([
        'example.com/image.jpg' => Http::response('bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $file = app(StoreImportedImageAction::class)->download('https://example.com/image.jpg');
    app(StoreImportedImageAction::class)->cleanup($file);

    // Second call: the file is already gone -- must stay a silent no-op.
    app(StoreImportedImageAction::class)->cleanup($file);

    expect(true)->toBeTrue();
});

it('names the downloaded file using the canonical Content-Type extension, not whatever extension the url happened to claim', function () {
    Http::fake([
        'example.com/photo.png' => Http::response('bytes', 200, [
            // Server sends actual JPEG bytes at a .png url -- Content-Type
            // is what StoreImportedImageAction trusts for the extension
            // (still only a hint; ImageIngestor is what verifies WHAT the
            // bytes really are).
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $file = app(StoreImportedImageAction::class)->download('https://example.com/photo.png');

    expect($file->getClientOriginalName())->toBe('photo.jpg');
});
