<?php

use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Exceptions\MediaIsNotPublicException;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Support\Facades\Storage;

it('resolves a public asset\'s url through its own disk', function () {
    Storage::fake('public');

    $asset = MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'media/post-images/dish.jpg',
        'visibility' => MediaVisibility::Public,
    ]);

    $url = app(MediaUrlResolver::class)->publicUrl($asset);

    expect($url)->toBe(Storage::disk('public')->url('media/post-images/dish.jpg'));
});

it('resolves a variant\'s url through its own disk and path', function () {
    Storage::fake('public');

    $variant = MediaVariant::factory()->create([
        'disk' => 'public',
        'path' => 'media/post-images/variants/feed_640.jpg',
    ]);

    $url = app(MediaUrlResolver::class)->publicUrl($variant);

    expect($url)->toBe(Storage::disk('public')->url('media/post-images/variants/feed_640.jpg'));
});

it('resolves two assets on different disks to each disk\'s own url', function () {
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

    $assetA = MediaAsset::factory()->create(['disk' => 'disk_a', 'path' => 'file.jpg']);
    $assetB = MediaAsset::factory()->create(['disk' => 'disk_b', 'path' => 'file.jpg']);

    $resolver = app(MediaUrlResolver::class);

    expect($resolver->publicUrl($assetA))->toBe('https://disk-a.example.test/file.jpg')
        ->and($resolver->publicUrl($assetB))->toBe('https://disk-b.example.test/file.jpg');
});

it('resolves a custom disk url (cdn-style) instead of building /storage/ manually', function () {
    config(['filesystems.disks.cdn_test' => [
        'driver' => 'local',
        'root' => storage_path('app/cdn_test'),
        'url' => 'https://cdn.example.test/media',
        'visibility' => 'public',
    ]]);

    $asset = MediaAsset::factory()->create(['disk' => 'cdn_test', 'path' => 'dish.jpg']);

    $url = app(MediaUrlResolver::class)->publicUrl($asset);

    expect($url)->toBe('https://cdn.example.test/media/dish.jpg')
        ->and($url)->not->toContain('/storage/');
});

it('throws when strictly resolving a private asset', function () {
    $asset = MediaAsset::factory()->create(['visibility' => MediaVisibility::Private]);

    expect(fn () => app(MediaUrlResolver::class)->publicUrl($asset))
        ->toThrow(MediaIsNotPublicException::class);
});

it('returns null instead of throwing when nullably resolving a private asset', function () {
    $asset = MediaAsset::factory()->create(['visibility' => MediaVisibility::Private]);

    expect(app(MediaUrlResolver::class)->publicUrlOrNull($asset))->toBeNull();
});

it('treats a variant as private when its parent asset is private', function () {
    $variant = MediaVariant::factory()
        ->for(MediaAsset::factory()->state(['visibility' => MediaVisibility::Private]), 'asset')
        ->create();

    expect(app(MediaUrlResolver::class)->publicUrlOrNull($variant))->toBeNull();
    expect(fn () => app(MediaUrlResolver::class)->publicUrl($variant))
        ->toThrow(MediaIsNotPublicException::class);
});

it('returns null for a null media without throwing', function () {
    expect(app(MediaUrlResolver::class)->publicUrlOrNull(null))->toBeNull();
});

it('resolves a url without checking whether the file actually exists', function () {
    Storage::fake('public');

    // No file was ever written at this path — resolving a URL is pure
    // string construction from disk config, not a filesystem existence
    // check.
    $asset = MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'media/post-images/never-written.jpg',
        'visibility' => MediaVisibility::Public,
    ]);

    expect(fn () => app(MediaUrlResolver::class)->publicUrl($asset))->not->toThrow(Throwable::class);
});
