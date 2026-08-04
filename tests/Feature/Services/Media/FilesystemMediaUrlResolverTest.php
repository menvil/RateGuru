<?php

use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Exceptions\MediaIsNotPublicException;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('resolves a public location\'s url through its own disk', function () {
    Storage::fake('public');

    $location = new MediaLocation('public', 'media/post-images/dish.jpg');

    $url = app(MediaUrlResolver::class)->publicUrl($location, MediaVisibility::Public);

    expect($url)->toBe(Storage::disk('public')->url('media/post-images/dish.jpg'));
});

it('resolves a variant\'s url using its own location and its parent\'s visibility', function () {
    Storage::fake('public');

    $variant = MediaVariant::factory()->create([
        'disk' => 'public',
        'path' => 'media/post-images/variants/feed_640.jpg',
    ]);

    // A future variant presenter would read these two independently — the
    // resolver itself never touches $variant->asset.
    $location = new MediaLocation($variant->disk, $variant->path);
    $visibility = $variant->asset->visibility;

    $url = app(MediaUrlResolver::class)->publicUrl($location, $visibility);

    expect($url)->toBe(Storage::disk('public')->url('media/post-images/variants/feed_640.jpg'));
});

it('resolves two locations on different disks to each disk\'s own url', function () {
    config(['filesystems.disks.disk_a' => [
        'driver' => 'local',
        'root' => storage_path('app/disk_a'),
        'url' => 'https://disk-a.example.test',
        'visibility' => 'public',
    ]]);
    config(['filesystems.disks.disk_b' => [
        'driver' => 'local',
        'root' => storage_path('app/disk_b'),
        'url' => 'https://disk-b.example.test',
        'visibility' => 'public',
    ]]);

    $resolver = app(MediaUrlResolver::class);

    expect($resolver->publicUrl(new MediaLocation('disk_a', 'file.jpg'), MediaVisibility::Public))
        ->toBe('https://disk-a.example.test/file.jpg')
        ->and($resolver->publicUrl(new MediaLocation('disk_b', 'file.jpg'), MediaVisibility::Public))
        ->toBe('https://disk-b.example.test/file.jpg');
});

it('resolves a custom disk url (cdn-style) instead of building /storage/ manually', function () {
    config(['filesystems.disks.cdn_test' => [
        'driver' => 'local',
        'root' => storage_path('app/cdn_test'),
        'url' => 'https://cdn.example.test/media',
        'visibility' => 'public',
    ]]);

    $url = app(MediaUrlResolver::class)->publicUrl(new MediaLocation('cdn_test', 'dish.jpg'), MediaVisibility::Public);

    expect($url)->toBe('https://cdn.example.test/media/dish.jpg')
        ->and($url)->not->toContain('/storage/');
});

it('throws when strictly resolving private visibility', function () {
    expect(fn () => app(MediaUrlResolver::class)->publicUrl(new MediaLocation('public', 'dish.jpg'), MediaVisibility::Private))
        ->toThrow(MediaIsNotPublicException::class);
});

it('returns null instead of throwing when nullably resolving private visibility', function () {
    expect(app(MediaUrlResolver::class)->publicUrlOrNull(new MediaLocation('public', 'dish.jpg'), MediaVisibility::Private))
        ->toBeNull();
});

it('returns null for a null location without throwing', function () {
    expect(app(MediaUrlResolver::class)->publicUrlOrNull(null, MediaVisibility::Public))->toBeNull();
});

it('returns null for a null visibility without throwing — the shape a missing/soft-deleted variant parent naturally produces', function () {
    // A caller resolving a MediaVariant's url derives visibility from
    // $variant->asset?->visibility — if the parent is missing or
    // soft-deleted, that expression is already null by the time it reaches
    // here, so the resolver just has to accept null cleanly, not fetch or
    // check anything itself.
    expect(app(MediaUrlResolver::class)->publicUrlOrNull(new MediaLocation('public', 'dish.jpg'), null))->toBeNull();
});

it('resolves a url without checking whether the file actually exists', function () {
    Storage::fake('public');

    expect(fn () => app(MediaUrlResolver::class)->publicUrl(
        new MediaLocation('public', 'media/post-images/never-written.jpg'),
        MediaVisibility::Public,
    ))->not->toThrow(Throwable::class);
});

it('never queries the database while resolving a url', function () {
    $asset = MediaAsset::factory()->create(['disk' => 'public', 'path' => 'dish.jpg']);
    $location = new MediaLocation($asset->disk, $asset->path);
    $visibility = $asset->visibility;

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    app(MediaUrlResolver::class)->publicUrl($location, $visibility);
    app(MediaUrlResolver::class)->publicUrlOrNull($location, $visibility);

    expect($queryCount)->toBe(0);
});
