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

it('writes the complete body to disk for a large multi-megabyte image, not a truncated prefix', function () {
    // Exercises the fwrite() write-loop across a payload large enough that
    // a single fwrite() call could, per the PHP manual's own documented
    // caveat, return fewer bytes than requested -- a distinct marker at the
    // very end proves the file was never silently truncated partway
    // through.
    $marker = 'END-OF-BODY-MARKER';
    $size = 2 * 1024 * 1024; // 2 MB, comfortably under the default cap
    $body = str_repeat('a', $size - strlen($marker)).$marker;

    Http::fake([
        'example.com/large.jpg' => Http::response($body, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $file = app(StoreImportedImageAction::class)->download('https://example.com/large.jpg');

    $writtenBytes = file_get_contents($file->getRealPath());

    expect(strlen($writtenBytes))->toBe(strlen($body))
        ->and($writtenBytes)->toBe($body);
});

// --- writeAll() partial-write loop, exercised directly via reflection ------
//
// fwrite() can't be made to return a genuine short write deterministically
// against a real file (short writes are an OS/stream-level edge case, not
// something a test can reliably trigger) or reliably simulated with
// sys_get_temp_dir() overrides (PHP caches its resolved value after
// Laravel's own bootstrap already calls it once). writeAll()'s injectable
// $writer parameter exists specifically so this loop is testable without
// either of those — production always uses the real fwrite() (the default
// when no $writer is passed); only these tests ever override it.

it('loops until the full body is written when the writer returns a short count first, then completes on retry', function () {
    $action = app(StoreImportedImageAction::class);
    $method = new ReflectionMethod($action, 'writeAll');

    $handle = fopen('php://temp', 'w+');
    $body = str_repeat('a', 100).str_repeat('b', 100); // 200 bytes total

    $calls = [];
    $writer = function ($h, string $chunk) use (&$calls): int {
        $calls[] = strlen($chunk);

        // First call: only accept the first 40 of what's offered (a
        // genuine short write). Every subsequent call writes fully.
        $accept = count($calls) === 1 ? min(40, strlen($chunk)) : strlen($chunk);

        return fwrite($h, substr($chunk, 0, $accept));
    };

    $method->invoke($action, $handle, $body, Closure::fromCallable($writer));

    rewind($handle);
    $writtenBody = stream_get_contents($handle);
    fclose($handle);

    expect($writtenBody)->toBe($body)
        ->and(count($calls))->toBeGreaterThan(1); // proves the loop actually retried, not one lucky full write
});

it('throws a typed failure when the writer returns false', function () {
    $action = app(StoreImportedImageAction::class);
    $method = new ReflectionMethod($action, 'writeAll');

    $handle = fopen('php://temp', 'w+');

    try {
        expect(fn () => $method->invoke($action, $handle, 'body', Closure::fromCallable(fn ($h, $c) => false)))
            ->toThrow(RuntimeException::class);
    } finally {
        fclose($handle);
    }
});

it('throws a typed failure when the writer returns zero', function () {
    $action = app(StoreImportedImageAction::class);
    $method = new ReflectionMethod($action, 'writeAll');

    $handle = fopen('php://temp', 'w+');

    try {
        expect(fn () => $method->invoke($action, $handle, 'body', Closure::fromCallable(fn ($h, $c) => 0)))
            ->toThrow(RuntimeException::class);
    } finally {
        fclose($handle);
    }
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
