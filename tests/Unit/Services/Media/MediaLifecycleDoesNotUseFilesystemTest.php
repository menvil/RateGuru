<?php

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A grep-based trip-wire, not exhaustive static analysis — same pattern as
 * tests/Unit/Models/MediaModelsDoNotUseFilesystemTest.php and
 * tests/Unit/Actions/UserFacingImageFlowsUseIngestorTest.php. Every physical
 * file operation the lifecycle layer needs (delete, deleteIfExists,
 * allFiles, lastModified) is already exposed through MediaStorage —
 * FilesystemMediaStorage is the only class allowed to touch
 * Storage/FilesystemManager directly.
 */
function assertLifecycleCodeDoesNotReferenceFilesystem(string $relativePath): void
{
    $source = file_get_contents(app_path($relativePath));

    expect($source)
        ->not->toContain('Storage::')
        ->not->toContain(Storage::class)
        ->not->toContain('Illuminate\Filesystem')
        ->not->toContain('FilesystemManager')
        ->not->toContain("app('filesystem')")
        ->not->toContain('app("filesystem")')
        // The Filesystem/Factory *contracts* are a second way to type-hint
        // straight into direct disk access via DI, without ever mentioning
        // FilesystemManager or the Storage facade by name — equally a
        // bypass of MediaStorage if reached directly.
        ->not->toContain('Illuminate\Contracts\Filesystem\Factory')
        ->not->toContain('Illuminate\Contracts\Filesystem\Filesystem');
}

it('does not call the Storage facade (or an equivalent filesystem API) directly from MediaLifecycleService', function () {
    assertLifecycleCodeDoesNotReferenceFilesystem('Services/Media/MediaLifecycleService.php');
});

it('does not call the Storage facade (or an equivalent filesystem API) directly from MediaReferenceChecker', function () {
    assertLifecycleCodeDoesNotReferenceFilesystem('Services/Media/MediaReferenceChecker.php');
});

it('does not call the Storage facade (or an equivalent filesystem API) directly from MediaOrphanScanner', function () {
    assertLifecycleCodeDoesNotReferenceFilesystem('Services/Media/MediaOrphanScanner.php');
});

it('does not call the Storage facade (or an equivalent filesystem API) directly from MediaAuditCommand', function () {
    assertLifecycleCodeDoesNotReferenceFilesystem('Console/Commands/MediaAuditCommand.php');
});

it('does not call the Storage facade (or an equivalent filesystem API) directly from MediaPurgeCommand', function () {
    assertLifecycleCodeDoesNotReferenceFilesystem('Console/Commands/MediaPurgeCommand.php');
});

it('does not add any new lifecycle model events to MediaAsset or MediaVariant', function () {
    // No remote I/O in model events, per the task's own hard rule — the
    // simplest way to guarantee that is to never introduce a booted()
    // method (or deleting/deleted/forceDeleted listeners), and never attach
    // an observer via #[ObservedBy(...)] (Laravel's attribute-based
    // alternative to the same static-listener mechanism, which an observer
    // class could just as easily hide deleting/deleted/forceDeleted hooks
    // behind) — on either model at all. All lifecycle logic lives in
    // MediaLifecycleService/the console commands/DeleteUserAccountAction
    // instead.
    foreach (['Models/MediaAsset.php', 'Models/MediaVariant.php'] as $relativePath) {
        $source = file_get_contents(app_path($relativePath));

        expect($source)
            ->not->toContain('function booted')
            ->not->toContain('::deleting(')
            ->not->toContain('::deleted(')
            ->not->toContain('::forceDeleted(')
            ->not->toContain('ObservedBy');
    }
});
