<?php

use App\Models\MediaAsset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('media_assets table exists', function () {
    expect(Schema::hasTable('media_assets'))->toBeTrue();
});

it('creates media_assets table with required columns', function () {
    expect(Schema::hasColumns('media_assets', [
        'id',
        'owner_user_id',
        'kind',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'extension',
        'byte_size',
        'width',
        'height',
        'aspect_ratio',
        'orientation',
        'checksum_sha256',
        'status',
        'visibility',
        'processing_error',
        'metadata',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});

it('does not store a canonical url column', function () {
    expect(Schema::hasColumn('media_assets', 'url'))->toBeFalse();
    expect(Schema::hasColumn('media_assets', 'public_url'))->toBeFalse();
    expect(Schema::hasColumn('media_assets', 'cdn_url'))->toBeFalse();
    expect(Schema::hasColumn('media_assets', 'full_url'))->toBeFalse();
});

it('rejects a second media asset at the same disk and path', function () {
    MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'posts/duplicate.jpg',
    ]);

    expect(fn () => MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'posts/duplicate.jpg',
    ]))->toThrow(QueryException::class);
});

it('stores an aspect ratio beyond what decimal(10,6) could hold', function () {
    $asset = MediaAsset::factory()->create([
        'width' => 15_000,
        'height' => 1,
        'aspect_ratio' => 15_000.0,
    ]);

    expect($asset->fresh()->aspect_ratio)->toBe(15_000.0);
});
