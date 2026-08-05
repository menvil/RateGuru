<?php

use App\Enums\MediaKind;
use App\Enums\MediaVisibility;
use App\Services\Media\MediaStoreRequest;
use App\Support\Media\ImageUploadStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('ingests and stores a real upload end to end', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $stored = app(ImageUploadStorer::class)->store($file, $request);

    expect($stored->disk)->toBe('public')
        ->and($stored->mimeType)->toBe('image/jpeg')
        ->and($stored->extension)->toBe('jpg')
        ->and($stored->width)->toBe(800)
        ->and($stored->height)->toBe(600)
        ->and($stored->originalFilename)->toBe('dish.jpg');

    Storage::disk('public')->assertExists($stored->path);
});

it('defaults to the Upload source when none is given', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    // No behavioral difference is asserted here beyond "it still works" —
    // ImageInputSource isn't consumed by ingestion logic today; this only
    // documents that the $source parameter is genuinely optional.
    $stored = app(ImageUploadStorer::class)->store($file, $request);

    expect($stored->disk)->toBe('public');
});
