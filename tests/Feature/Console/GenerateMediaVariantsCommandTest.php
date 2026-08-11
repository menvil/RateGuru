<?php

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function createReadyPostAsset(int $width = 2400, int $height = 1600, string $path = 'posts/2026/08/master.jpg'): MediaAsset
{
    $asset = MediaAsset::factory()->postImage()->dimensions($width, $height)->create(['path' => $path]);
    Storage::disk($asset->disk)->put($asset->path, jpegMarkerBytes($width, $height));

    return $asset;
}

it('generates variants for every eligible asset by default', function () {
    $asset = createReadyPostAsset();

    $this->artisan('media:generate-variants')->assertExitCode(0);

    expect($asset->variants()->count())->toBe(3);
});

it('skips assets that already have every applicable variant unless --force is given', function () {
    $asset = createReadyPostAsset();

    $this->artisan('media:generate-variants')->assertExitCode(0);
    expect(MediaVariant::query()->count())->toBe(3);

    $firstIds = MediaVariant::query()->pluck('id')->sort()->values()->all();

    // Missing-only (the default): nothing left to do, no new/changed rows.
    $this->artisan('media:generate-variants')->assertExitCode(0);
    expect(MediaVariant::query()->pluck('id')->sort()->values()->all())->toBe($firstIds);

    // --force reprocesses the same asset; still idempotent on the row count.
    $this->artisan('media:generate-variants --force')->assertExitCode(0);
    expect(MediaVariant::query()->count())->toBe(3);
});

it('filters by --asset', function () {
    $target = createReadyPostAsset(path: 'posts/2026/08/target.jpg');
    $other = createReadyPostAsset(path: 'posts/2026/08/other.jpg');

    $this->artisan('media:generate-variants --asset='.$target->id)->assertExitCode(0);

    expect($target->variants()->count())->toBe(3)
        ->and($other->variants()->count())->toBe(0);
});

it('rejects a non-numeric --asset value', function () {
    $this->artisan('media:generate-variants --asset=not-a-number')->assertExitCode(1);
});

it('filters by --kind', function () {
    $post = createReadyPostAsset(path: 'posts/2026/08/master.jpg');
    $avatar = MediaAsset::factory()->avatar()->dimensions(256, 256)->create(['path' => 'avatars/9/master.jpg']);
    Storage::disk($avatar->disk)->put($avatar->path, jpegMarkerBytes(256, 256));

    $this->artisan('media:generate-variants --kind=avatar')->assertExitCode(0);

    expect($post->variants()->count())->toBe(0)
        ->and($avatar->variants()->count())->toBe(2);
});

it('rejects an invalid --kind value', function () {
    $this->artisan('media:generate-variants --kind=bogus')->assertExitCode(1);
});

it('respects a small --chunk size while still processing every eligible asset', function () {
    createReadyPostAsset(path: 'posts/2026/08/one.jpg');
    createReadyPostAsset(path: 'posts/2026/08/two.jpg');
    createReadyPostAsset(path: 'posts/2026/08/three.jpg');

    $this->artisan('media:generate-variants --chunk=1')->assertExitCode(0);

    expect(MediaVariant::query()->count())->toBe(9);
});
