<?php

use App\Models\MediaAsset;
use App\Models\Post;
use App\Services\Media\MediaLifecycleService;
use Illuminate\Support\Carbon;

it('never considers an active (not soft-deleted) asset purgeable', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    expect($asset->trashed())->toBeFalse()
        ->and(app(MediaLifecycleService::class)->isPurgeable($asset))->toBeFalse();
});

it('does not consider a soft-deleted asset purgeable while still within the grace period', function () {
    config(['media.lifecycle.purge_grace_days' => 7]);

    $this->travelTo(Carbon::parse('2026-01-10 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();

    $this->travelTo(Carbon::parse('2026-01-15 12:00:00')); // 5 days later, still within 7

    expect(app(MediaLifecycleService::class)->isPurgeable($asset->fresh()))->toBeFalse();
});

it('considers an old, unreferenced, soft-deleted asset purgeable', function () {
    config(['media.lifecycle.purge_grace_days' => 7]);

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00')); // 19 days later

    expect(app(MediaLifecycleService::class)->isPurgeable($asset->fresh()))->toBeTrue();
});

it('never considers a referenced asset purgeable, no matter how long it has been soft-deleted', function () {
    config(['media.lifecycle.purge_grace_days' => 7]);

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    $asset->delete();

    $this->travelTo(Carbon::parse('2026-02-01 12:00:00')); // 31 days later

    expect(app(MediaLifecycleService::class)->isPurgeable($asset->fresh()))->toBeFalse();
});

it('is purgeable at the exact boundary and not one second before it, with frozen time', function () {
    config(['media.lifecycle.purge_grace_days' => 7]);

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();

    $this->travelTo(Carbon::parse('2026-01-08 11:59:59')); // one second short of 7 days
    expect(app(MediaLifecycleService::class)->isPurgeable($asset->fresh()))->toBeFalse();

    $this->travelTo(Carbon::parse('2026-01-08 12:00:00')); // exactly 7 days
    expect(app(MediaLifecycleService::class)->isPurgeable($asset->fresh()))->toBeTrue();
});

it('honors an explicit graceDays override instead of the configured default', function () {
    config(['media.lifecycle.purge_grace_days' => 7]);

    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();

    $this->travelTo(Carbon::parse('2026-01-03 12:00:00')); // 2 days later

    expect(app(MediaLifecycleService::class)->isPurgeable($asset->fresh(), graceDays: 7))->toBeFalse()
        ->and(app(MediaLifecycleService::class)->isPurgeable($asset->fresh(), graceDays: 1))->toBeTrue();
});
