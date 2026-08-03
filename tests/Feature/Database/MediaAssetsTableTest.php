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

it('stores the widest aspect ratio the width/height columns can produce', function () {
    // 2147483647 = PHP_INT32_MAX, the largest value Postgres's `integer`
    // column can hold (Laravel's unsignedInteger() has no native unsigned
    // type to map to there, unlike MariaDB). width/height carry no
    // validation bounds of their own, so this is the true worst case across
    // every supported database, not just an "unusually wide photo".
    $asset = MediaAsset::factory()->create([
        'width' => 2_147_483_647,
        'height' => 1,
        'aspect_ratio' => 2_147_483_647.0,
    ]);

    expect($asset->fresh()->aspect_ratio)->toBe(2_147_483_647.0);
});
