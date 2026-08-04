<?php

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A grep-based trip-wire (same pattern as
 * tests/Unit/Models/MediaModelsDoNotUseFilesystemTest.php), not exhaustive
 * static analysis: it targets the two specific user-facing image entry
 * points, not a repo-wide regex. putContents()/raw Storage:: writes are the
 * sanctioned bypass for trusted, server-generated content (demo seeders)
 * only — a user-controlled upload flow reaching for either directly would
 * skip ImageIngestor's decode/validate/EXIF-orient/re-encode/strip pipeline
 * entirely.
 */
function assertActionDoesNotBypassImageNormalization(string $relativeActionPath): void
{
    $source = file_get_contents(app_path($relativeActionPath));

    expect($source)
        ->not->toContain('putContents(')
        ->not->toContain('Storage::put(')
        ->not->toContain('Storage::disk(')
        ->not->toContain(Storage::class);
}

it('does not bypass image normalization in CreatePostAction', function () {
    assertActionDoesNotBypassImageNormalization('Actions/Posts/CreatePostAction.php');
});

it('does not bypass image normalization in UpdateUserProfileAction', function () {
    assertActionDoesNotBypassImageNormalization('Actions/Profile/UpdateUserProfileAction.php');
});
