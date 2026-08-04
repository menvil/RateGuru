<?php

use App\Enums\MediaKind;
use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Exceptions;
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

it('normalizes a filesystem exception thrown during store() into a narrow exception', function () {
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
        app(MediaStorage::class)->store(UploadedFile::fake()->image('dish.jpg', 800, 600), $request);
    } catch (MediaStorageException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getPrevious()->getMessage())->toBe('Simulated disk outage.');
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

it('deletes the newly written file when metadata extraction fails after a successful write', function () {
    Storage::fake('public');

    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getMimeType')->andThrow(new RuntimeException('Simulated metadata failure.'));

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    expect(fn () => app(MediaStorage::class)->store($file, $request))
        ->toThrow(RuntimeException::class, 'Simulated metadata failure.');

    expect(Storage::disk('public')->allFiles('media/post-images'))->toBeEmpty();
});

it('reports a cleanup failure but still propagates the original metadata exception', function () {
    Exceptions::fake();

    $diskAdapter = Mockery::mock(Filesystem::class);
    $diskAdapter->shouldReceive('put')->once()->andReturn(true);
    $diskAdapter->shouldReceive('delete')->once()->andReturn(false);

    $filesystem = Mockery::mock(FilesystemManager::class);
    $filesystem->shouldReceive('disk')->with('public')->andReturn($diskAdapter);

    app()->instance(FilesystemManager::class, $filesystem);
    $storage = app(MediaStorage::class);

    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getMimeType')->andThrow(new RuntimeException('Simulated metadata failure.'));

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    expect(fn () => $storage->store($file, $request))
        ->toThrow(RuntimeException::class, 'Simulated metadata failure.');

    Exceptions::assertReported(MediaStorageException::class);
});

it('does not delete the file when metadata extraction fails for a reused explicit path', function () {
    Storage::fake('public');

    // Simulates a demo seeder rerun: the deterministic path already has a
    // real, still-referenced file sitting at it before this call even
    // starts.
    Storage::disk('public')->put('demo/posts/sample-01.jpg', 'existing-owner-bytes');

    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getMimeType')->andThrow(new RuntimeException('Simulated metadata failure.'));

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: null,
        explicitPath: 'demo/posts/sample-01.jpg',
    );

    expect(fn () => app(MediaStorage::class)->store($file, $request))
        ->toThrow(RuntimeException::class, 'Simulated metadata failure.');

    // The write itself (rewriting the same deterministic path) already
    // happened and is fine to leave in place — what must not happen is
    // deleting it as if this call had exclusive ownership of a brand new
    // path.
    Storage::disk('public')->assertExists('demo/posts/sample-01.jpg');
});

it('deletes the file when metadata extraction fails on a first-time explicit path', function () {
    Storage::fake('public');

    // Unlike a seeder rerun, nothing exists at this deterministic path yet
    // — this is the first write, so it's just as safe to clean up as any
    // freshly generated path.
    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getMimeType')->andThrow(new RuntimeException('Simulated metadata failure.'));

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: null,
        explicitPath: 'demo/posts/sample-01.jpg',
    );

    expect(fn () => app(MediaStorage::class)->store($file, $request))
        ->toThrow(RuntimeException::class, 'Simulated metadata failure.');

    Storage::disk('public')->assertMissing('demo/posts/sample-01.jpg');
});

it('maps an unreadable uploaded file to a narrow exception instead of touching the disk', function () {
    Storage::fake('public');

    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getContent')->andThrow(new RuntimeException('Simulated unreadable temp file.'));

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    expect(fn () => app(MediaStorage::class)->store($file, $request))
        ->toThrow(MediaStorageException::class, 'Could not read uploaded file [dish.jpg].');

    expect(Storage::disk('public')->allFiles('media/post-images'))->toBeEmpty();
});

it('throws and cleans up when the uploaded file cannot be decoded as an image', function () {
    Storage::fake('public');

    // Garbage bytes rather than a real image — this bypasses the upstream
    // 'image' validation rule the way a direct, unit-level store() call
    // always does, so store() itself has to be the one that refuses it.
    $file = UploadedFile::fake()->create('not-an-image.jpg', 10);

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    expect(fn () => app(MediaStorage::class)->store($file, $request))
        ->toThrow(ImageException::class);

    expect(Storage::disk('public')->allFiles('media/post-images'))->toBeEmpty();
});
