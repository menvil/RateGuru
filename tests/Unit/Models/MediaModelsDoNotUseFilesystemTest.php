<?php

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A grep-based trip-wire, not exhaustive static analysis: it can't catch an
 * aliased import (`use ... Storage as Disk;`) or some other filesystem API
 * reached through a variable. It does catch the realistic ways a model
 * would plausibly end up touching the filesystem directly.
 */
function assertModelDoesNotReferenceFilesystem(string $relativeModelPath): void
{
    $source = file_get_contents(app_path($relativeModelPath));

    expect($source)
        ->not->toContain('Storage::')
        ->not->toContain(Storage::class)
        ->not->toContain('Illuminate\Filesystem')
        ->not->toContain('FilesystemManager')
        ->not->toContain("app('filesystem')")
        ->not->toContain('app("filesystem")');
}

it('does not call the Storage facade (or an equivalent filesystem API) directly from the MediaAsset model', function () {
    assertModelDoesNotReferenceFilesystem('Models/MediaAsset.php');
});

it('does not call the Storage facade (or an equivalent filesystem API) directly from the MediaVariant model', function () {
    assertModelDoesNotReferenceFilesystem('Models/MediaVariant.php');
});
