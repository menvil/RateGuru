<?php

use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\GdImageVariantProcessor;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaVariantPathGenerator;
use App\Services\Media\MediaVariantWriter;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('writes the variant file and row for a first-time generation', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    $bytes = jpegMarkerBytes(2400, 1600);
    $spec = variantSpec();

    $variant = app(MediaVariantWriter::class)->write($asset, $bytes, $spec);

    expect($variant->media_asset_id)->toBe($asset->id)
        ->and($variant->name)->toBe(MediaVariantName::PostFeed640)
        ->and($variant->path)->toBe('posts/2026/08/master/post_feed_640.jpg')
        ->and($variant->width)->toBe(640)
        ->and($variant->height)->toBe(427);

    Storage::disk('public')->assertExists($variant->path);
    expect(MediaVariant::query()->count())->toBe(1);
});

it('is idempotent across repeated writes for the same asset and spec', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    $bytes = jpegMarkerBytes(2400, 1600);
    $spec = variantSpec();
    $writer = app(MediaVariantWriter::class);

    $first = $writer->write($asset, $bytes, $spec);
    $second = $writer->write($asset, $bytes, $spec);

    expect(MediaVariant::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->path)->toBe($first->path);
});

it('deletes the just-written file when the database upsert fails on a first-time write', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    $bytes = jpegMarkerBytes(2400, 1600);
    $spec = variantSpec();
    $exception = null;

    MediaVariant::saving(function (): void {
        throw new RuntimeException('Simulated first-time DB failure.');
    });

    try {
        app(MediaVariantWriter::class)->write($asset, $bytes, $spec);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        MediaVariant::flushEventListeners();
    }

    expect($exception?->getMessage())->toBe('Simulated first-time DB failure.')
        ->and(MediaVariant::query()->count())->toBe(0);

    $expectedPath = 'posts/2026/08/master/post_feed_640.jpg';
    Storage::disk('public')->assertMissing($expectedPath);
});

it('preserves the existing working file when the database upsert fails on regeneration', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    $bytes = jpegMarkerBytes(2400, 1600);
    $spec = variantSpec();
    $writer = app(MediaVariantWriter::class);

    $writer->write($asset, $bytes, $spec);
    $expectedPath = 'posts/2026/08/master/post_feed_640.jpg';
    Storage::disk('public')->assertExists($expectedPath);

    $exception = null;

    MediaVariant::saving(function (): void {
        throw new RuntimeException('Simulated regeneration DB failure.');
    });

    try {
        $writer->write($asset, $bytes, $spec);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        MediaVariant::flushEventListeners();
    }

    expect($exception?->getMessage())->toBe('Simulated regeneration DB failure.');
    // The file was already overwritten before the failing upsert — it must
    // not be deleted, since that would leave nothing at all.
    Storage::disk('public')->assertExists($expectedPath);
    expect(MediaVariant::query()->count())->toBe(1);
});

it('reports a cleanup failure but still propagates the original database exception', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    $bytes = jpegMarkerBytes(2400, 1600);
    $spec = variantSpec();

    $mediaStorage = Mockery::mock(MediaStorage::class);
    $mediaStorage->shouldReceive('putContents')->once();
    $mediaStorage->shouldReceive('delete')->once()->andThrow(new RuntimeException('Simulated cleanup failure.'));

    $writer = new MediaVariantWriter(new GdImageVariantProcessor, new MediaVariantPathGenerator, $mediaStorage);

    Exceptions::fake();
    $exception = null;

    MediaVariant::saving(function (): void {
        throw new RuntimeException('Simulated first-time DB failure.');
    });

    try {
        $writer->write($asset, $bytes, $spec);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        MediaVariant::flushEventListeners();
    }

    // The propagated exception is the original DB failure, not the cleanup
    // failure — the two share a class, so the message is what pins it down.
    expect($exception?->getMessage())->toBe('Simulated first-time DB failure.');

    Exceptions::assertReported(
        fn (RuntimeException $reported): bool => $reported->getMessage() === 'Simulated cleanup failure.',
    );
});
