<?php

use App\Enums\MediaKind;
use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\FilesystemMediaStorage;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaPathGenerator;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores the uploaded file on the requested disk, not a hardcoded one', function () {
    Storage::fake('a_custom_disk_name');

    $request = new MediaStoreRequest(
        disk: 'a_custom_disk_name',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $stored = app(MediaStorage::class)->store(UploadedFile::fake()->image('dish.jpg', 800, 600), $request);

    expect($stored->disk)->toBe('a_custom_disk_name');
    Storage::disk('a_custom_disk_name')->assertExists($stored->path);
});

it('returns disk, path, dimensions, mime, and byte size, but no url property', function () {
    Storage::fake('public');

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $stored = app(MediaStorage::class)->store(UploadedFile::fake()->image('dish.jpg', 1600, 900), $request);

    expect($stored->disk)->toBe('public')
        ->and($stored->path)->not->toBeEmpty()
        ->and($stored->originalFilename)->toBe('dish.jpg')
        ->and($stored->mimeType)->toBe('image/jpeg')
        ->and($stored->extension)->toBe('jpg')
        ->and($stored->byteSize)->toBeGreaterThan(0)
        ->and($stored->width)->toBe(1600)
        ->and($stored->height)->toBe(900);

    expect(property_exists($stored, 'url'))->toBeFalse();
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

    $stored = app(MediaStorage::class)->store(UploadedFile::fake()->image('face.jpg', 512, 512), $request);

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

    $stored = app(MediaStorage::class)->store(UploadedFile::fake()->image('dish.jpg'), $request);

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

    $storage = new FilesystemMediaStorage($filesystem, new MediaPathGenerator);

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
