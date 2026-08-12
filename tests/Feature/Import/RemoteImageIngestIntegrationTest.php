<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Enums\ImageInputSource;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\MediaStoreRequest;
use App\Support\Media\ImageUploadStorer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The end-to-end proof for the two-layer invariant this whole PR is built
 * around: the network layer (SafeImportHttpClient/StoreImportedImageAction)
 * only ever decides WHERE bytes are safe to come from; ImageIngestor is the
 * only thing that decides WHAT those bytes actually are. Neither layer's
 * job is done by the other.
 */
beforeEach(function () {
    Storage::fake('public');
    bindFakeHostResolver();
});

it('takes a real remote JPEG all the way through StoreImportedImageAction and ImageIngestor to a normalized master', function () {
    Http::fake([
        'example.com/photo.jpg' => Http::response(jpegMarkerBytes(400, 300), 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $uploadedFile = app(StoreImportedImageAction::class)->download('https://example.com/photo.jpg');

    $stored = app(ImageUploadStorer::class)->store(
        $uploadedFile,
        MediaStoreRequest::forPostImage(1),
        ImageInputSource::UrlImport,
    );

    expect($stored->mimeType)->toBe('image/jpeg')
        ->and($stored->width)->toBeGreaterThan(0)
        ->and($stored->height)->toBeGreaterThan(0);

    Storage::disk($stored->disk)->assertExists($stored->path);
});

it('lets the network fetch succeed for a fake JPEG (HTML body at a .jpg url with a spoofed Content-Type), then has ImageIngestor reject it — Content-Type is never trusted as proof of what the bytes are', function () {
    Http::fake([
        'example.com/fake.jpg' => Http::response('<html><body>not an image</body></html>', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $uploadedFile = app(StoreImportedImageAction::class)->download('https://example.com/fake.jpg');

    expect(fn () => app(ImageUploadStorer::class)->store(
        $uploadedFile,
        MediaStoreRequest::forPostImage(1),
        ImageInputSource::UrlImport,
    ))->toThrow(ImageIngestException::class);
});

it('rejects an oversized remote image at the download layer, before ImageIngestor ever sees it', function () {
    $tooLarge = ImageIngestPolicy::fromConfig()->maxBytes + 1;

    Http::fake([
        'example.com/huge.jpg' => Http::response(str_repeat('a', $tooLarge), 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    expect(fn () => app(StoreImportedImageAction::class)->download('https://example.com/huge.jpg'))
        ->toThrow(ImportFetchException::class);
});

it('never downloads more than ImageIngestor would accept anyway, even when import.max_image_bytes is configured larger', function () {
    $ingestLimit = ImageIngestPolicy::fromConfig()->maxBytes;
    config(['import.max_image_bytes' => $ingestLimit * 10]);

    Http::fake([
        'example.com/huge.jpg' => Http::response(str_repeat('a', $ingestLimit + 1), 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    expect(fn () => app(StoreImportedImageAction::class)->download('https://example.com/huge.jpg'))
        ->toThrow(ImportFetchException::class);
});

it('blocks a remote image whose url redirects to a private address, before any request to that address is made', function () {
    bindFakeHostResolver(['private-target.example' => ['10.0.0.9']]);

    Http::fake([
        'example.com/redirecting.jpg' => Http::response('', 302, [
            'Location' => 'https://private-target.example/internal.jpg',
        ]),
    ]);

    expect(fn () => app(StoreImportedImageAction::class)->download('https://example.com/redirecting.jpg'))
        ->toThrow(UnsafeImportUrlException::class);
});
