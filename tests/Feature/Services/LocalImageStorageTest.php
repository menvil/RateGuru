<?php

use App\Models\User;
use App\Services\Images\ImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores uploaded post image locally', function () {
    config(['rateguru.images.disk' => 'public']);
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $stored = app(ImageStorage::class)->storePostImage($file, $user);

    expect($stored->path)->not->toBeEmpty();
    expect($stored->disk)->toBe('public');

    Storage::disk('public')->assertExists($stored->path);
});
