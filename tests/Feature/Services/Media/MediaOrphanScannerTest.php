<?php

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaOrphanScanner;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * MediaOrphanScanner reads a physical file's *real* filesystem mtime
 * (MediaStorage::lastModified()) — Storage::fake() writes to a real local
 * temp directory, so Carbon::setTestNow() has no effect on it. Rather than
 * time-travel, these tests drive the age boundary through
 * orphan_grace_hours itself: 0 makes any just-written file immediately
 * "old enough" (its real mtime is always <= the real "now" the cutoff is
 * computed from); a large value keeps a just-written file well within
 * grace, deterministically, regardless of the real wall-clock time the
 * test happens to run at.
 */
it('does not flag a file that has a matching MediaAsset row', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/known.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');

    $orphans = app(MediaOrphanScanner::class)->scan();

    expect($orphans)->toBeEmpty();
});

it('does not flag a file that has a matching MediaVariant row', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/master.jpg']);
    $variant = MediaVariant::factory()->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'media/post-images/master/variant.jpg',
    ]);
    Storage::disk('public')->put($asset->path, 'master-bytes');
    Storage::disk('public')->put($variant->path, 'variant-bytes');

    $orphans = app(MediaOrphanScanner::class)->scan();

    expect($orphans)->toBeEmpty();
});

it('does not flag a file still owned by an asset that is soft-deleted but within its purge grace period', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/graced.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    $asset->delete();

    $orphans = app(MediaOrphanScanner::class)->scan();

    expect($orphans)->toBeEmpty();
});

it('does not flag a file that has no matching row but is not old enough yet', function () {
    config(['media.lifecycle.orphan_grace_hours' => 24]);
    Storage::disk('public')->put('media/post-images/fresh-orphan.jpg', 'bytes');

    $orphans = app(MediaOrphanScanner::class)->scan();

    expect($orphans)->toBeEmpty();
});

it('flags a file that has no matching row once it clears the orphan grace period', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/old-orphan.jpg', 'bytes');

    $orphans = app(MediaOrphanScanner::class)->scan();

    expect($orphans)->toHaveCount(1)
        ->and($orphans[0]->disk)->toBe('public')
        ->and($orphans[0]->path)->toBe('media/post-images/old-orphan.jpg');
});

it('only scans configured media directories, not the whole disk', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('unrelated/other-app-file.txt', 'bytes');

    $orphans = app(MediaOrphanScanner::class)->scan();

    expect($orphans)->toBeEmpty();
});
