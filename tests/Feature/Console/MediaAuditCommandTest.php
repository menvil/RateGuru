<?php

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('is always successful, even with nothing to report', function () {
    $this->artisan('media:audit')->assertExitCode(0);
});

it('never changes anything: an eligible purgeable asset is left untouched', function () {
    $asset = createPurgeableAsset();
    $masterPath = $asset->path;

    $this->artisan('media:audit')->assertExitCode(0);

    Storage::disk('public')->assertExists($masterPath);
    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('classifies a referenced, active asset as healthy', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $this->artisan('media:audit')
        ->expectsOutputToContain('Healthy, referenced: 1')
        ->assertExitCode(0);
});

it('classifies an active, unreferenced asset separately from healthy ones', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');

    $this->artisan('media:audit')
        ->expectsOutputToContain('Active, but unreferenced (unexpected — investigate): 1')
        ->assertExitCode(0);
});

it('classifies a soft-deleted asset within grace separately from a purgeable one', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00'));

    $this->artisan('media:audit')
        ->expectsOutputToContain('Soft-deleted, within grace period: 1')
        ->expectsOutputToContain('Soft-deleted, purgeable now: 0')
        ->assertExitCode(0);
});

it('classifies a grace-expired, unreferenced, soft-deleted asset as purgeable', function () {
    $asset = createPurgeableAsset();

    $this->artisan('media:audit')
        ->expectsOutputToContain('Soft-deleted, purgeable now: 1')
        ->assertExitCode(0);
});

it('reports an asset whose master file is missing', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/missing.jpg']);
    // Deliberately never writing bytes for this asset's path.

    $this->artisan('media:audit')
        ->expectsOutputToContain('Assets with a missing master file: 1')
        ->assertExitCode(0);
});

it('reports a variant whose physical file is missing', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/master.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    MediaVariant::factory()->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'media/post-images/master/variant.jpg',
    ]);
    // Deliberately never writing bytes for the variant's path.

    $this->artisan('media:audit')
        ->expectsOutputToContain('Variants with a missing physical file: 1')
        ->assertExitCode(0);
});

it('reports physical orphan candidates', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/orphan.jpg', 'bytes');

    $this->artisan('media:audit')
        ->expectsOutputToContain('Candidate files: 1')
        ->assertExitCode(0);
});

it('does not flag a fresh physical orphan candidate still within the orphan grace period', function () {
    config(['media.lifecycle.orphan_grace_hours' => 24]);
    Storage::disk('public')->put('media/post-images/fresh.jpg', 'bytes');

    $this->artisan('media:audit')
        ->expectsOutputToContain('Candidate files: 0')
        ->assertExitCode(0);
});
