<?php

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('does not reference the filesystem directly from the MediaAsset model', function () {
    $source = file_get_contents(app_path('Models/MediaAsset.php'));

    expect($source)
        ->not->toContain('Storage::')
        ->not->toContain(Storage::class);
});

it('does not reference the filesystem directly from the MediaVariant model', function () {
    $source = file_get_contents(app_path('Models/MediaVariant.php'));

    expect($source)
        ->not->toContain('Storage::')
        ->not->toContain(Storage::class);
});
