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

it('purges an eligible asset by default', function () {
    $asset = createPurgeableAsset();
    $masterPath = $asset->path;

    $this->artisan('media:purge')->assertExitCode(0);

    Storage::disk('public')->assertMissing($masterPath);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('does not touch an active asset', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    $this->artisan('media:purge')->assertExitCode(0);

    expect(MediaAsset::find($asset->id))->not->toBeNull();
});

it('does not touch a soft-deleted asset still within the grace period', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00'));

    $this->artisan('media:purge')->assertExitCode(0);

    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('does not touch a referenced asset even if it is grace-expired', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-02-01 12:00:00'));

    $this->artisan('media:purge')->assertExitCode(0);

    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('dry-run reports what would be purged without deleting anything', function () {
    $asset = createPurgeableAsset();
    $masterPath = $asset->path;

    $this->artisan('media:purge --dry-run')
        ->expectsOutputToContain('Would purge 1 of 1 candidate media assets (dry run')
        ->assertExitCode(0);

    Storage::disk('public')->assertExists($masterPath);
    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
});

it('filters by --asset, leaving other eligible assets untouched', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $target = MediaAsset::factory()->postImage()->create(['path' => 'posts/target.jpg']);
    $other = MediaAsset::factory()->postImage()->create(['path' => 'posts/other.jpg']);
    $target->delete();
    $other->delete();
    Carbon::setTestNow(Carbon::parse('2026-02-01 12:00:00'));

    $this->artisan('media:purge --asset='.$target->id)->assertExitCode(0);

    expect(MediaAsset::withTrashed()->find($target->id))->toBeNull()
        ->and(MediaAsset::withTrashed()->find($other->id)->trashed())->toBeTrue();
});

it('rejects a non-numeric --asset value', function () {
    $this->artisan('media:purge --asset=not-a-number')->assertExitCode(1);
});

it('overrides the configured grace period with --older-than', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00')); // 2 days later, within the 7-day default

    // Default grace (7 days) would skip this asset; a 1-day override makes it eligible.
    $this->artisan('media:purge --older-than=1')->assertExitCode(0);

    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('rejects an invalid --older-than value', function () {
    $this->artisan('media:purge --older-than=not-a-number')->assertExitCode(1);
});

it('reports physical orphan candidates without deleting them when --orphans is passed without --force', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/orphan.jpg', 'bytes');

    $this->artisan('media:purge --orphans')
        ->expectsOutputToContain('1 physical orphan file(s) found')
        ->assertExitCode(0);

    Storage::disk('public')->assertExists('media/post-images/orphan.jpg');
});

it('reports physical orphan candidates without deleting them for --orphans --dry-run even with --force', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/orphan.jpg', 'bytes');

    $this->artisan('media:purge --orphans --dry-run --force')->assertExitCode(0);

    Storage::disk('public')->assertExists('media/post-images/orphan.jpg');
});

it('deletes physical orphan files only with --orphans --force', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/orphan.jpg', 'bytes');

    $this->artisan('media:purge --orphans --force')->assertExitCode(0);

    Storage::disk('public')->assertMissing('media/post-images/orphan.jpg');
});

it('never deletes a physical orphan candidate that is still within the orphan grace period', function () {
    config(['media.lifecycle.orphan_grace_hours' => 24]);
    Storage::disk('public')->put('media/post-images/fresh.jpg', 'bytes');

    $this->artisan('media:purge --orphans --force')->assertExitCode(0);

    Storage::disk('public')->assertExists('media/post-images/fresh.jpg');
});

it('never touches a file that still has a matching MediaAsset row, even in orphan force mode', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/owned.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');

    $this->artisan('media:purge --orphans --force')->assertExitCode(0);

    Storage::disk('public')->assertExists($asset->path);
});

it('is idempotent: running the default purge twice in a row is safe', function () {
    $asset = createPurgeableAsset();

    $this->artisan('media:purge')->assertExitCode(0);
    $this->artisan('media:purge')->assertExitCode(0); // nothing left to do, no error

    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('is idempotent: running orphan purge twice in a row is safe', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/orphan.jpg', 'bytes');

    $this->artisan('media:purge --orphans --force')->assertExitCode(0);
    $this->artisan('media:purge --orphans --force')->assertExitCode(0); // already gone, no error

    Storage::disk('public')->assertMissing('media/post-images/orphan.jpg');
});

it('purges every applicable variant along with the master', function () {
    $asset = createPurgeableAsset();
    $variantPaths = $asset->variants()->pluck('path')->all();

    $this->artisan('media:purge')->assertExitCode(0);

    foreach ($variantPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    expect(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(0);
});
