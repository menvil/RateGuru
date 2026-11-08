<?php

use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use Database\Seeders\Support\DemoPostMediaGenerator;
use Illuminate\Support\Facades\Storage;

/*
 * The demo's post pictures are made again on every seeding, onto the same
 * row, whatever became of that row since.
 */

it('makes a demo picture again over its released row instead of beside it', function () {
    Storage::fake('public');
    $generator = app(DemoPostMediaGenerator::class);

    $first = $generator->create('demo/posts/sample-01.svg', 0);
    $made = $first->only(['width', 'height', 'mime_type', 'byte_size', 'status']);
    // Whatever became of the row since: another size, type and state.
    $first->forceFill(['width' => 1, 'height' => 1, 'mime_type' => 'image/png', 'byte_size' => 1, 'status' => MediaStatus::Failed])->save();
    $first->delete();

    $again = $generator->create('demo/posts/sample-01.svg', 0);

    expect($again->id)->toBe($first->id)
        ->and($again->trashed())->toBeFalse()
        ->and(MediaAsset::withTrashed()->where('path', 'demo/posts/sample-01.svg')->count())->toBe(1)
        ->and($again->fresh()->only(['width', 'height', 'mime_type', 'byte_size', 'status']))->toEqual($made)
        ->and($again->fresh()->byte_size)->toBe(strlen(Storage::disk('public')->get('demo/posts/sample-01.svg')));
});
