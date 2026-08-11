<?php

use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('has the expected retry configuration', function () {
    $job = new GenerateMediaVariantsJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300])
        ->and($job->timeout)->toBe(120);
});

it('logs a warning and returns without failing when the media asset no longer exists', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('GenerateMediaVariantsJob: media asset not found, skipping.', ['media_asset_id' => 999999]);

    (new GenerateMediaVariantsJob(999999))->handle(app(MediaVariantGenerator::class));

    expect(MediaVariant::query()->count())->toBe(0);
});

it('delegates to the media variant generator for an existing ready asset', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    Storage::disk($asset->disk)->put($asset->path, jpegMarkerBytes(2400, 1600));

    (new GenerateMediaVariantsJob($asset->id))->handle(app(MediaVariantGenerator::class));

    expect($asset->variants()->count())->toBe(3);
});

it('still finds a soft-deleted media asset instead of treating it as missing', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    $asset->delete();

    Log::shouldReceive('warning')->never();

    (new GenerateMediaVariantsJob($asset->id))->handle(app(MediaVariantGenerator::class));

    // Trashed assets are still skipped by the generator itself, just not
    // reported as "not found" by the job.
    expect(MediaVariant::query()->count())->toBe(0);
});
