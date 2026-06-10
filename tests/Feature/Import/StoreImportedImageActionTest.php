<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
