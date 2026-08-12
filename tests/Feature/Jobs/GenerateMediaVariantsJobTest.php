<?php

use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\GeneratedMediaVariant;
use App\Services\Media\ImageVariantProcessor;
use App\Services\Media\MediaVariantGenerator;
use App\Services\Media\MediaVariantSpecification;
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

    expect($asset->variants()->count())->toBe(4);
});

it('logs a structured failure and rethrows when variant generation fails, without swallowing the exception', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    Storage::disk($asset->disk)->put($asset->path, jpegMarkerBytes(2400, 1600));

    $failingProcessor = new class implements ImageVariantProcessor
    {
        public function generate(string $masterBytes, string $mimeType, MediaVariantSpecification $specification): GeneratedMediaVariant
        {
            throw new RuntimeException('Simulated processor failure.');
        }
    };
    $this->app->instance(ImageVariantProcessor::class, $failingProcessor);

    // Log::spy() (not a strict shouldReceive expectation) because the
    // MediaVariantGenerator layer below also logs its own, differently
    // shaped error for the same failure — both layers are expected to log,
    // this assertion only cares about the job layer's own entry.
    Log::spy();

    $job = new GenerateMediaVariantsJob($asset->id);

    expect(fn () => $job->handle(app()->make(MediaVariantGenerator::class)))
        ->toThrow(RuntimeException::class, 'Simulated processor failure.');

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($asset): bool {
            $json = json_encode($context);

            return $message === 'GenerateMediaVariantsJob: variant generation failed.'
                && $context['media_asset_id'] === $asset->id
                && array_key_exists('job_uuid', $context)
                && $context['attempt'] === 1
                && $context['exception_class'] === RuntimeException::class
                && ! str_contains($json, 'jpegMarkerBytes');
        })
        ->atLeast()->once();
});

it('does not log an error and generates every variant, including open graph, when nothing fails', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    Storage::disk($asset->disk)->put($asset->path, jpegMarkerBytes(2400, 1600));

    Log::shouldReceive('error')->never();

    (new GenerateMediaVariantsJob($asset->id))->handle(app(MediaVariantGenerator::class));

    $names = $asset->variants()->get()->map(fn (MediaVariant $v) => $v->name->value)->sort()->values()->all();
    expect($names)->toBe(['open_graph', 'post_detail_1920', 'post_feed_1280', 'post_feed_640']);
});

it('defaults force to false and leaves an already-generated variant file untouched', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    Storage::disk($asset->disk)->put($asset->path, jpegMarkerBytes(2400, 1600));

    (new GenerateMediaVariantsJob($asset->id))->handle(app(MediaVariantGenerator::class));
    $originalTimestamps = $asset->variants()->pluck('updated_at', 'id')
        ->map(fn ($timestamp) => $timestamp->toIso8601String())
        ->all();

    // Re-running without force must not rewrite anything already generated.
    (new GenerateMediaVariantsJob($asset->id))->handle(app(MediaVariantGenerator::class));

    $refreshedTimestamps = $asset->variants()->pluck('updated_at', 'id')
        ->map(fn ($timestamp) => $timestamp->toIso8601String())
        ->all();

    expect($refreshedTimestamps)->toBe($originalTimestamps);
});

it('force true regenerates every applicable variant even though they already exist', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
    ]);
    Storage::disk($asset->disk)->put($asset->path, jpegMarkerBytes(2400, 1600));

    (new GenerateMediaVariantsJob($asset->id))->handle(app(MediaVariantGenerator::class));
    $originalCount = $asset->variants()->count();

    (new GenerateMediaVariantsJob($asset->id, force: true))->handle(app(MediaVariantGenerator::class));

    // Still the same set of variants (upserted in place, not duplicated) —
    // force rewrites them, it doesn't create parallel rows.
    expect($asset->variants()->count())->toBe($originalCount);
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
