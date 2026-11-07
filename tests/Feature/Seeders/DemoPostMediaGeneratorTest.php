<?php

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
    $first->delete();

    $again = $generator->create('demo/posts/sample-01.svg', 0);

    expect($again->id)->toBe($first->id)
        ->and($again->trashed())->toBeFalse()
        ->and(MediaAsset::withTrashed()->where('path', 'demo/posts/sample-01.svg')->count())->toBe(1);
});
