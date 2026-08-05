<?php

use App\Enums\ImageInputSource;
use App\Enums\MediaKind;
use App\Enums\MediaVisibility;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageInput;
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

    // Mocks ImageIngestor and inspects the ImageInput it's actually called
    // with — asserting the storer's own default, not just that storing
    // still works, so a future accidental default change (e.g. to
    // UrlImport) would fail this test.
    $imageIngestor = Mockery::mock(ImageIngestor::class);
    $imageIngestor->shouldReceive('ingest')
        ->once()
        ->with(Mockery::on(fn (ImageInput $input): bool => $input->source === ImageInputSource::Upload), Mockery::any())
        ->andReturn(normalizedFixture());
    app()->instance(ImageIngestor::class, $imageIngestor);

    $stored = app(ImageUploadStorer::class)->store($file, $request);

    expect($stored->disk)->toBe('public');
});
