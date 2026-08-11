<?php

use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\GdImageVariantProcessor;
use App\Services\Media\GeneratedMediaVariant;
use App\Services\Media\ImageVariantProcessor;
use App\Services\Media\MediaVariantGenerator;
use App\Services\Media\MediaVariantSpecification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function storeMasterBytesFor(MediaAsset $asset, string $bytes): void
{
    Storage::disk($asset->disk)->put($asset->path, $bytes);
}

it('generates every applicable variant, including open graph, for a post image asset', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));

    app(MediaVariantGenerator::class)->generateAll($asset);

    $names = $asset->variants()->get()->map(fn (MediaVariant $v) => $v->name->value)->sort()->values()->all();

    expect($names)->toBe(['open_graph', 'post_detail_1920', 'post_feed_1280', 'post_feed_640']);

    $openGraph = $asset->variants()->where('name', MediaVariantName::OpenGraph->value)->firstOrFail();
    expect($openGraph->width)->toBe(1200)
        ->and($openGraph->height)->toBe(630)
        ->and($openGraph->mime_type)->toBe('image/jpeg');
});

it('only generates the requested variant when a filter is given', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));

    app(MediaVariantGenerator::class)->generateAll($asset, MediaVariantName::OpenGraph);

    $names = $asset->variants()->get()->map(fn (MediaVariant $v) => $v->name->value)->all();

    expect($names)->toBe(['open_graph']);
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

    expect(MediaVariant::query()->count())->toBe(4);
});

it('preserves already-succeeded variants and their ids when a later spec in the same run fails, and a clean retry only fills the gap', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));

    // The real GdImageVariantProcessor generates real variants for every
    // spec except open_graph (registry order: it's last), which always
    // throws — exercising the task's own "run 1: feed succeeds, OG fails;
    // run 2: feed untouched, OG succeeds" scenario without needing to mock
    // the writer or the registry itself.
    $failingProcessor = new class implements ImageVariantProcessor
    {
        public function generate(string $masterBytes, string $mimeType, MediaVariantSpecification $specification): GeneratedMediaVariant
        {
            if ($specification->name === MediaVariantName::OpenGraph) {
                throw new RuntimeException('Simulated open graph generation failure.');
            }

            return app(GdImageVariantProcessor::class)->generate($masterBytes, $mimeType, $specification);
        }
    };

    $this->app->instance(ImageVariantProcessor::class, $failingProcessor);
    $generator = app()->make(MediaVariantGenerator::class);

    expect(fn () => $generator->generateAll($asset))
        ->toThrow(RuntimeException::class, 'Simulated open graph generation failure.');

    $afterFirstRun = MediaVariant::query()->pluck('id', 'name')->all();
    // Row iteration order isn't guaranteed across database engines without
    // an explicit ORDER BY, so the set of names is compared unordered.
    expect(collect(array_keys($afterFirstRun))->sort()->values()->all())
        ->toBe(['post_detail_1920', 'post_feed_1280', 'post_feed_640'])
        ->and(MediaVariant::query()->where('name', MediaVariantName::OpenGraph->value)->exists())->toBeFalse();

    // Second run, real (non-throwing) processor: already-succeeded rows must
    // be untouched (same ids, not regenerated), and the missing open_graph
    // variant is filled in.
    $this->app->forgetInstance(ImageVariantProcessor::class);
    app()->make(MediaVariantGenerator::class)->generateAll($asset->fresh());

    $afterSecondRun = MediaVariant::query()->pluck('id', 'name')->all();
    expect($afterSecondRun['post_feed_640'])->toBe($afterFirstRun['post_feed_640'])
        ->and($afterSecondRun['post_feed_1280'])->toBe($afterFirstRun['post_feed_1280'])
        ->and($afterSecondRun['post_detail_1920'])->toBe($afterFirstRun['post_detail_1920'])
        ->and($afterSecondRun)->toHaveKey('open_graph');
});

it('logs and rethrows when the master image bytes cannot be read', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/missing-master.jpg',
    ]);
    // Deliberately not writing any bytes at this path.

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'MediaVariantGenerator: master image missing or unreadable.'
            && $context['media_asset_id'] === $asset->id
            && is_string($context['exception_class']));

    expect(fn () => app(MediaVariantGenerator::class)->generateAll($asset))
        ->toThrow(MediaStorageException::class);

    expect(MediaVariant::query()->count())->toBe(0);
});

it('logs a failure with the variant name and asset id, without leaking image bytes, and rethrows', function () {
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create([
        'path' => 'posts/2026/08/master.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    storeMasterBytesFor($asset, jpegMarkerBytes(2400, 1600));

    $failingProcessor = new class implements ImageVariantProcessor
    {
        public function generate(string $masterBytes, string $mimeType, MediaVariantSpecification $specification): GeneratedMediaVariant
        {
            throw new RuntimeException('Simulated processor failure.');
        }
    };
    $this->app->instance(ImageVariantProcessor::class, $failingProcessor);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($asset): bool {
            $json = json_encode($context);

            return $message === 'MediaVariantGenerator: variant generation failed.'
                && $context['media_asset_id'] === $asset->id
                && $context['variant'] === MediaVariantName::PostFeed640->value
                && $context['exception_class'] === RuntimeException::class
                && ! str_contains($json, 'jpegMarkerBytes')
                && strlen($json) < 500; // no serialized image bytes hiding in the context
        });

    expect(fn () => app()->make(MediaVariantGenerator::class)->generateAll($asset))->toThrow(RuntimeException::class);
});
