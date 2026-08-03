<?php

use App\Enums\ImageOrientation;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('belongs to an owner user', function () {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->create(['owner_user_id' => $owner->id]);

    expect($asset->owner)->toBeInstanceOf(User::class)
        ->and($asset->owner->id)->toBe($owner->id);
});

it('has no owner by default', function () {
    $asset = MediaAsset::factory()->create();

    expect($asset->owner)->toBeNull();
});

it('has many variants', function () {
    $asset = MediaAsset::factory()->create();
    MediaVariant::factory()->named('feed_640')->create(['media_asset_id' => $asset->id]);
    MediaVariant::factory()->named('detail_1920')->create(['media_asset_id' => $asset->id]);

    expect($asset->variants)->toHaveCount(2);
});

it('casts kind, status, visibility and orientation to their enums', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(1600, 900)->create();

    expect($asset->kind)->toBe(MediaKind::PostImage)
        ->and($asset->status)->toBeInstanceOf(MediaStatus::class)
        ->and($asset->visibility)->toBeInstanceOf(MediaVisibility::class)
        ->and($asset->orientation)->toBeInstanceOf(ImageOrientation::class);
});

it('is soft deletable', function () {
    $asset = MediaAsset::factory()->create();

    $asset->delete();

    expect(MediaAsset::query()->find($asset->id))->toBeNull()
        ->and(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull();
});

it('media variant belongs to its asset', function () {
    $asset = MediaAsset::factory()->create();
    $variant = MediaVariant::factory()->create(['media_asset_id' => $asset->id]);

    expect($variant->asset)->toBeInstanceOf(MediaAsset::class)
        ->and($variant->asset->id)->toBe($asset->id);
});
