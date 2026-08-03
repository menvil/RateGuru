<?php

use App\Models\User;
use App\Services\Images\ImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores uploaded post image locally with disk, dimensions, mime, and byte size, but no url', function () {
    config(['rateguru.images.disk' => 'public']);
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 1600, 900);

    $stored = app(ImageStorage::class)->storePostImage($file, $user);

    expect($stored->path)->not->toBeEmpty()
        ->and($stored->disk)->toBe('public')
        ->and($stored->originalFilename)->toBe('dish.jpg')
        ->and($stored->mimeType)->toBe('image/jpeg')
        ->and($stored->extension)->toBe('jpg')
        ->and($stored->byteSize)->toBeGreaterThan(0)
        ->and($stored->width)->toBe(1600)
        ->and($stored->height)->toBe(900);

    expect(property_exists($stored, 'url'))->toBeFalse();

    Storage::disk('public')->assertExists($stored->path);
});

it('stores an avatar under the avatars directory', function () {
    config(['rateguru.images.disk' => 'public']);
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('face.jpg', 512, 512);

    $stored = app(ImageStorage::class)->storeAvatar($file, $user);

    expect($stored->disk)->toBe('public')
        ->and($stored->path)->toStartWith('avatars/')
        ->and($stored->width)->toBe(512)
        ->and($stored->height)->toBe(512);

    Storage::disk('public')->assertExists($stored->path);
});
