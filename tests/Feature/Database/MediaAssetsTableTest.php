<?php

use App\Enums\MediaType;
use App\Models\MediaAsset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates media_assets table with required columns', function () {
    expect(Schema::hasColumns('media_assets', [
        'id',
        'owner_user_id',
        'kind',
        'media_type',
        'duration_ms',
        'has_audio',
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

it('takes a row written without a media type for a silent image, as every asset stored before video was', function () {
    // Written the way rows were before the column existed: no media type, no
    // duration, no sound.
    $id = DB::table('media_assets')->insertGetId([
        'kind' => 'post_image',
        'disk' => 'public',
        'path' => 'media/post-images/2026/11/'.fake()->uuid().'.jpg',
        'mime_type' => 'image/jpeg',
        'byte_size' => 1_024,
        'status' => 'ready',
        'visibility' => 'public',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $asset = MediaAsset::query()->findOrFail($id);

    expect($asset->media_type)->toBe(MediaType::Image)
        ->and($asset->duration_ms)->toBeNull()
        ->and($asset->has_audio)->toBeFalse();
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
