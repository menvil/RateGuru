<?php

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function storeMasterBytesFor(MediaAsset $asset, string $bytes): void
{
    Storage::disk($asset->disk)->put($asset->path, $bytes);
}

it('generates every applicable contain variant for a post image asset', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));

    app(MediaVariantGenerator::class)->generateAll($asset);

    $names = $asset->variants()->get()->map(fn (MediaVariant $v) => $v->name->value)->sort()->values()->all();

    expect($names)->toBe(['post_detail_1920', 'post_feed_1280', 'post_feed_640']);
});

it('generates only the avatar variants that do not require an upscale', function () {
    // 100x100 source: avatar_128 (needs >=128) is skipped, avatar_256 too.
    $asset = MediaAsset::factory()->avatar()->dimensions(100, 100)->create([
        'path' => 'avatars/7/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(100, 100));

    app(MediaVariantGenerator::class)->generateAll($asset);

    expect($asset->variants()->count())->toBe(0);
});

it('generates only the avatar variant that fits without upscaling', function () {
    // 200x200 source: avatar_128 fits, avatar_256 would require an upscale.
    $asset = MediaAsset::factory()->avatar()->dimensions(200, 200)->create([
        'path' => 'avatars/7/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(200, 200));

    app(MediaVariantGenerator::class)->generateAll($asset);

    $names = $asset->variants()->get()->map(fn (MediaVariant $v) => $v->name->value)->all();

    expect($names)->toBe(['avatar_128']);
});

it('skips a soft-deleted asset', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));
    $asset->delete();

    expect($asset->trashed())->toBeTrue();

    app(MediaVariantGenerator::class)->generateAll($asset);

    expect(MediaVariant::query()->count())->toBe(0);
});

it('skips an asset that is not ready', function () {
    $asset = MediaAsset::factory()->postImage()->failed()->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);

    app(MediaVariantGenerator::class)->generateAll($asset);

    expect(MediaVariant::query()->count())->toBe(0);
});

it('skips a non-raster mime type such as svg', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(800, 600)->create([
        'path' => 'posts/2026/08/master.svg',
        'mime_type' => 'image/svg+xml',
    ]);

    app(MediaVariantGenerator::class)->generateAll($asset);

    expect(MediaVariant::query()->count())->toBe(0);
});

it('is idempotent: rerunning generateAll does not create duplicate rows', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));

    $generator = app(MediaVariantGenerator::class);
    $generator->generateAll($asset);
    $generator->generateAll($asset->fresh());

    expect(MediaVariant::query()->count())->toBe(3);
});
