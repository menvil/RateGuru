<?php

use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('media_variants table exists', function () {
    expect(Schema::hasTable('media_variants'))->toBeTrue();
});

it('creates media_variants table with required columns', function () {
    expect(Schema::hasColumns('media_variants', [
        'id',
        'media_asset_id',
        'name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'byte_size',
        'width',
        'height',
        'checksum_sha256',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('does not store a canonical url column', function () {
    expect(Schema::hasColumn('media_variants', 'url'))->toBeFalse();
});

it('enforces a unique name per media asset', function () {
    $asset = MediaAsset::factory()->create();
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $asset->id]);

    expect(fn () => MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $asset->id]))
        ->toThrow(QueryException::class);
});

it('allows the same variant name across different media assets', function () {
    $first = MediaAsset::factory()->create();
    $second = MediaAsset::factory()->create();

    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $first->id]);
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $second->id]);

    expect(MediaVariant::query()->where('name', MediaVariantName::PostFeed640)->count())->toBe(2);
});

it('cascades deletes from media asset to its variants', function () {
    $asset = MediaAsset::factory()->create();
    $variant = MediaVariant::factory()->create(['media_asset_id' => $asset->id]);

    $asset->forceDelete();

    expect(MediaVariant::query()->find($variant->id))->toBeNull();
});
