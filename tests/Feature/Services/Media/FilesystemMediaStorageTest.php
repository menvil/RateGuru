<?php

use App\Enums\MediaKind;
use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\NormalizedImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

function normalizedFixture(string $bytes = 'normalized-jpeg-bytes'): NormalizedImage
{
    return new NormalizedImage(
        bytes: $bytes,
        mimeType: 'image/jpeg',
        extension: 'jpg',
        byteSize: strlen($bytes),
        width: 800,
        height: 600,
    );
}

it('stores a normalized image on the requested disk, not a hardcoded one', function () {
    Storage::fake('a_custom_disk_name');

    $request = new MediaStoreRequest(
        disk: 'a_custom_disk_name',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $stored = app(MediaStorage::class)->storeNormalized(normalizedFixture(), $request, 'dish.jpg');

    expect($stored->disk)->toBe('a_custom_disk_name');
    Storage::disk('a_custom_disk_name')->assertExists($stored->path);
});

it('returns disk, path, dimensions, mime, extension, and byte size straight from the normalized image', function () {
    Storage::fake('public');

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $normalized = normalizedFixture();
    $stored = app(MediaStorage::class)->storeNormalized($normalized, $request, 'dish.jpg');

    expect($stored->disk)->toBe('public')
        ->and($stored->path)->not->toBeEmpty()
        ->and($stored->originalFilename)->toBe('dish.jpg')
        ->and($stored->mimeType)->toBe('image/jpeg')
        ->and($stored->extension)->toBe('jpg')
        ->and($stored->byteSize)->toBe($normalized->byteSize)
        ->and($stored->width)->toBe(800)
        ->and($stored->height)->toBe(600);

    Storage::disk('public')->assertExists($stored->path);
    expect(Storage::disk('public')->get($stored->path))->toBe($normalized->bytes);
});

it('truncates an unusually long original filename but leaves a null filename alone', function () {
    Storage::fake('public');

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $storage = app(MediaStorage::class);
    $longName = str_repeat('a', 300).'.jpg';

    $stored = $storage->storeNormalized(normalizedFixture(), $request, $longName);
    expect(mb_strlen($stored->originalFilename))->toBe(255);

    $storedWithoutName = $storage->storeNormalized(normalizedFixture(), $request, null);
    expect($storedWithoutName->originalFilename)->toBeNull();
});

it('stores an avatar nested under the owner user id', function () {
    Storage::fake('public');

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/avatars',
        kind: MediaKind::Avatar,
        visibility: MediaVisibility::Public,
        ownerUserId: 42,
    );

    $stored = app(MediaStorage::class)->storeNormalized(normalizedFixture(), $request, 'face.jpg');

    expect($stored->path)->toStartWith('media/avatars/42/');
    Storage::disk('public')->assertExists($stored->path);
});

it('respects an explicit deterministic path for seeders', function () {
    Storage::fake('public');

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: null,
        explicitPath: 'demo/posts/sample-01.jpg',
    );

    $stored = app(MediaStorage::class)->storeNormalized(normalizedFixture(), $request, 'dish.jpg');

    expect($stored->path)->toBe('demo/posts/sample-01.jpg');
});

it('checks existence on the location\'s own disk', function () {
    Storage::fake('public');
    Storage::fake('a_second_disk');

    $storage = app(MediaStorage::class);

    Storage::disk('public')->put('media/post-images/present.jpg', 'bytes');

    expect($storage->exists(new MediaLocation('public', 'media/post-images/present.jpg')))->toBeTrue()
        ->and($storage->exists(new MediaLocation('public', 'media/post-images/missing.jpg')))->toBeFalse()
        ->and($storage->exists(new MediaLocation('a_second_disk', 'media/post-images/present.jpg')))->toBeFalse();
});

it('reads the size of a stored file', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/post-images/file.jpg', '0123456789');

    $size = app(MediaStorage::class)->size(new MediaLocation('public', 'media/post-images/file.jpg'));

    expect($size)->toBe(10);
});

it('throws a narrow exception when asked for the size of a missing file', function () {
    Storage::fake('public');

    expect(fn () => app(MediaStorage::class)->size(new MediaLocation('public', 'media/post-images/missing.jpg')))
        ->toThrow(MediaStorageException::class);
});

it('reads a stream of a stored file\'s contents', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/post-images/file.jpg', 'hello world');

    $stream = app(MediaStorage::class)->readStream(new MediaLocation('public', 'media/post-images/file.jpg'));

    expect(is_resource($stream))->toBeTrue();
    expect(stream_get_contents($stream))->toBe('hello world');
    fclose($stream);
});

it('throws a narrow exception when asked to read a missing file', function () {
    Storage::fake('public');

    expect(fn () => app(MediaStorage::class)->readStream(new MediaLocation('public', 'media/post-images/missing.jpg')))
        ->toThrow(MediaStorageException::class);
});

it('deletes a stored file from its own disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/post-images/file.jpg', 'bytes');

    app(MediaStorage::class)->delete(new MediaLocation('public', 'media/post-images/file.jpg'));

    Storage::disk('public')->assertMissing('media/post-images/file.jpg');
});

it('throws a narrow exception when the underlying disk reports the delete failed', function () {
    // Laravel's local disk treats deleting a missing file as a successful
    // no-op, so a real failed delete has to be forced at the adapter level
    // rather than by pointing at a path that doesn't exist.
    $diskAdapter = Mockery::mock(Filesystem::class);
    $diskAdapter->shouldReceive('delete')->once()->andReturn(false);

    $filesystem = Mockery::mock(FilesystemManager::class);
    $filesystem->shouldReceive('disk')->with('public')->andReturn($diskAdapter);

    app()->instance(FilesystemManager::class, $filesystem);
    $storage = app(MediaStorage::class);

    expect(fn () => $storage->delete(new MediaLocation('public', 'media/post-images/file.jpg')))
        ->toThrow(MediaStorageException::class);
});

it('writes in-process generated content at an explicit location via putContents', function () {
    Storage::fake('public');

    app(MediaStorage::class)->putContents(
        new MediaLocation('public', 'demo/posts/generated.svg'),
        '<svg></svg>',
        MediaVisibility::Public,
    );

    Storage::disk('public')->assertExists('demo/posts/generated.svg');
    expect(Storage::disk('public')->get('demo/posts/generated.svg'))->toBe('<svg></svg>');
});

it('normalizes a filesystem exception thrown during storeNormalized() into a narrow exception', function () {
    $diskAdapter = Mockery::mock(Filesystem::class);
    $diskAdapter->shouldReceive('put')->once()->andThrow(new RuntimeException('Simulated disk outage.'));

    $filesystem = Mockery::mock(FilesystemManager::class);
    $filesystem->shouldReceive('disk')->with('public')->andReturn($diskAdapter);

    app()->instance(FilesystemManager::class, $filesystem);

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $caught = null;

    try {
        app(MediaStorage::class)->storeNormalized(normalizedFixture(), $request, 'dish.jpg');
    } catch (MediaStorageException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getPrevious()->getMessage())->toBe('Simulated disk outage.');
});

it('throws a narrow exception when the disk reports storeNormalized()\'s write failed', function () {
    $diskAdapter = Mockery::mock(Filesystem::class);
    $diskAdapter->shouldReceive('put')->once()->andReturn(false);

    $filesystem = Mockery::mock(FilesystemManager::class);
    $filesystem->shouldReceive('disk')->with('public')->andReturn($diskAdapter);

    app()->instance(FilesystemManager::class, $filesystem);

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    expect(fn () => app(MediaStorage::class)->storeNormalized(normalizedFixture(), $request, 'dish.jpg'))
        ->toThrow(MediaStorageException::class);
});

it('normalizes a filesystem exception thrown during putContents() into a narrow exception', function () {
    $diskAdapter = Mockery::mock(Filesystem::class);
    $diskAdapter->shouldReceive('put')->once()->andThrow(new RuntimeException('Simulated disk outage.'));

    $filesystem = Mockery::mock(FilesystemManager::class);
    $filesystem->shouldReceive('disk')->with('public')->andReturn($diskAdapter);

    app()->instance(FilesystemManager::class, $filesystem);

    $caught = null;

    try {
        app(MediaStorage::class)->putContents(
            new MediaLocation('public', 'demo/posts/generated.svg'),
            '<svg></svg>',
            MediaVisibility::Public,
        );
    } catch (MediaStorageException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getPrevious()->getMessage())->toBe('Simulated disk outage.');
});
