<?php

use App\Enums\ImageOrientation;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\User;

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
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $asset->id]);
    MediaVariant::factory()->named(MediaVariantName::PostDetail1920)->create(['media_asset_id' => $asset->id]);

    expect($asset->variants)->toHaveCount(2);
});

it('casts kind, media type, status, visibility and orientation to their enums', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(1600, 900)->create();

    expect($asset->kind)->toBe(MediaKind::PostImage)
        ->and($asset->fresh()->media_type)->toBe(MediaType::Image)
        ->and($asset->status)->toBeInstanceOf(MediaStatus::class)
        ->and($asset->visibility)->toBeInstanceOf(MediaVisibility::class)
        ->and($asset->orientation)->toBeInstanceOf(ImageOrientation::class);
});

it('reads a video asset back with its type, duration and sound', function () {
    $asset = MediaAsset::factory()->create([
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'media_type' => MediaType::Video,
        'duration_ms' => 14_960,
        'has_audio' => true,
    ])->fresh();

    expect($asset->media_type)->toBe(MediaType::Video)
        ->and($asset->duration_ms)->toBe(14_960)
        ->and($asset->has_audio)->toBeTrue();
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
