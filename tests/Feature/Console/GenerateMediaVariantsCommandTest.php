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

    expect($asset->variants()->count())->toBe(4);
});

it('skips assets that already have every applicable variant unless --force is given', function () {
    $asset = createReadyPostAsset();

    $this->artisan('media:generate-variants')->assertExitCode(0);
    expect(MediaVariant::query()->count())->toBe(4);

    $firstIds = MediaVariant::query()->pluck('id')->sort()->values()->all();

    // Missing-only (the default): nothing left to do, no new/changed rows.
    $this->artisan('media:generate-variants')->assertExitCode(0);
    expect(MediaVariant::query()->pluck('id')->sort()->values()->all())->toBe($firstIds);

    // --force reprocesses the same asset; still idempotent on the row count.
    $this->artisan('media:generate-variants --force')->assertExitCode(0);
    expect(MediaVariant::query()->count())->toBe(4);
});

it('filters by --asset', function () {
    $target = createReadyPostAsset(path: 'posts/2026/08/target.jpg');
    $other = createReadyPostAsset(path: 'posts/2026/08/other.jpg');

    $this->artisan('media:generate-variants --asset='.$target->id)->assertExitCode(0);

    expect($target->variants()->count())->toBe(4)
        ->and($other->variants()->count())->toBe(0);
});

it('filters by --variant, generating only the requested variant', function () {
    $asset = createReadyPostAsset();

    $this->artisan('media:generate-variants --variant=open_graph')->assertExitCode(0);

    $names = $asset->variants()->get()->map(fn (MediaVariant $v) => $v->name->value)->all();
    expect($names)->toBe(['open_graph']);
});

it('rejects an invalid --variant value', function () {
    $this->artisan('media:generate-variants --variant=bogus')->assertExitCode(1);
});

it('treats a variant as missing when its row exists but its physical file is gone, and --missing-only regenerates only that file', function () {
    $asset = createReadyPostAsset();

    $this->artisan('media:generate-variants')->assertExitCode(0);
    expect(MediaVariant::query()->count())->toBe(4);

    $variant = $asset->variants()->where('name', 'open_graph')->firstOrFail();
    Storage::disk($variant->disk)->delete($variant->path);
    Storage::disk($variant->disk)->assertMissing($variant->path);

    $otherIds = MediaVariant::query()->where('name', '!=', 'open_graph')->pluck('id')->sort()->values()->all();

    $this->artisan('media:generate-variants --missing-only')->assertExitCode(0);

    Storage::disk($variant->disk)->assertExists($variant->path);
    expect(MediaVariant::query()->count())->toBe(4)
        ->and(MediaVariant::query()->where('name', '!=', 'open_graph')->pluck('id')->sort()->values()->all())->toBe($otherIds);
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

    expect(MediaVariant::query()->count())->toBe(12);
});
