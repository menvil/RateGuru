<?php

use App\Enums\MediaPurgeOutcome;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaReferenceChecker;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\NormalizedImage;
use App\Services\Media\StoredMedia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * Delegates every call to a real MediaStorage except deleteIfExists(), which
 * throws for one specific path — lets a test simulate "this exact file's
 * delete failed for a real storage reason" (variant vs master) without
 * mocking the whole interface per scenario.
 */
final class SelectiveDeleteFailureStorage implements MediaStorage
{
    public function __construct(
        private readonly MediaStorage $real,
        private readonly string $failingPath,
    ) {}

    public function storeNormalized(NormalizedImage $image, MediaStoreRequest $request, ?string $originalFilename): StoredMedia
    {
        return $this->real->storeNormalized($image, $request, $originalFilename);
    }

    public function putContents(MediaLocation $location, string $contents, MediaVisibility $visibility): void
    {
        $this->real->putContents($location, $contents, $visibility);
    }

    public function exists(MediaLocation $location): bool
    {
        return $this->real->exists($location);
    }

    public function size(MediaLocation $location): int
    {
        return $this->real->size($location);
    }

    public function readStream(MediaLocation $location)
    {
        return $this->real->readStream($location);
    }

    public function delete(MediaLocation $location): void
    {
        $this->real->delete($location);
    }

    public function deleteIfExists(MediaLocation $location): void
    {
        if ($location->path === $this->failingPath) {
            throw new RuntimeException('Simulated storage failure.');
        }

        $this->real->deleteIfExists($location);
    }

    public function allFiles(string $disk, string $directory): array
    {
        return $this->real->allFiles($disk, $directory);
    }

    public function lastModified(MediaLocation $location): int
    {
        return $this->real->lastModified($location);
    }
}

afterEach(function () {
    Carbon::setTestNow();
});

it('purges an old, unreferenced, soft-deleted asset: files and rows all removed', function () {
    $asset = createPurgeableAsset();
    $variantPaths = $asset->variants()->pluck('path')->all();
    $masterPath = $asset->path;

    $result = app(MediaLifecycleService::class)->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::Purged)
        ->and($result->assetId)->toBe($asset->id);

    Storage::disk('public')->assertMissing($masterPath);
    foreach ($variantPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull()
        ->and(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(0);
});

it('does not purge an active asset', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    $result = app(MediaLifecycleService::class)->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::NotEligible);
    expect(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull();
});

it('does not purge a soft-deleted asset still within its grace period', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00'));

    $result = app(MediaLifecycleService::class)->purge($asset->fresh());

    expect($result->outcome)->toBe(MediaPurgeOutcome::NotEligible);
    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();

    Carbon::setTestNow();
});

it('does not purge a referenced asset even if it is old and soft-deleted', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-02-01 12:00:00'));

    $result = app(MediaLifecycleService::class)->purge($asset->fresh());

    expect($result->outcome)->toBe(MediaPurgeOutcome::NotEligible);
    expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();

    Carbon::setTestNow();
});

it('completes cleanup when the master file is already missing', function () {
    $asset = createPurgeableAsset();
    Storage::disk('public')->delete($asset->path);

    $result = app(MediaLifecycleService::class)->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::Purged);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('completes cleanup when one variant file is already missing', function () {
    $asset = createPurgeableAsset();
    $missingVariantPath = $asset->variants()->first()->path;
    Storage::disk('public')->delete($missingVariantPath);

    $result = app(MediaLifecycleService::class)->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::Purged);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('completes cleanup when every physical file is already missing, only the DB rows remain', function () {
    $asset = createPurgeableAsset();
    foreach ($asset->variants as $variant) {
        Storage::disk('public')->delete($variant->path);
    }
    Storage::disk('public')->delete($asset->path);

    $result = app(MediaLifecycleService::class)->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::Purged);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('leaves every row intact and preserves the original exception when a variant file delete fails for a real reason', function () {
    $asset = createPurgeableAsset();
    $failingVariantPath = $asset->variants()->first()->path;

    $failingStorage = new SelectiveDeleteFailureStorage(app(MediaStorage::class), $failingVariantPath);
    $lifecycle = new MediaLifecycleService(app(MediaReferenceChecker::class), $failingStorage);

    $result = $lifecycle->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::Failed)
        ->and($result->exception)->toBeInstanceOf(RuntimeException::class)
        ->and($result->exception->getMessage())->toBe('Simulated storage failure.');

    // Nothing force-deleted: the asset row, and every variant row (including
    // ones whose files a real run would have already deleted before
    // reaching the failing one), all remain — retryable.
    $reloaded = MediaAsset::withTrashed()->find($asset->id);
    expect($reloaded)->not->toBeNull()
        ->and($reloaded->trashed())->toBeTrue()
        ->and(MediaVariant::query()->where('media_asset_id', $asset->id)->count())->toBe(2);
});

it('leaves every row intact when the master file delete fails for a real reason, even though every variant was already removed', function () {
    $asset = createPurgeableAsset();
    $masterPath = $asset->path;
    $variantPaths = $asset->variants()->pluck('path')->all();

    $failingStorage = new SelectiveDeleteFailureStorage(app(MediaStorage::class), $masterPath);
    $lifecycle = new MediaLifecycleService(app(MediaReferenceChecker::class), $failingStorage);

    $result = $lifecycle->purge($asset);

    expect($result->outcome)->toBe(MediaPurgeOutcome::Failed);

    foreach ($variantPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    Storage::disk('public')->assertExists($masterPath); // never reached — the throw happened on this exact call

    $reloaded = MediaAsset::withTrashed()->find($asset->id);
    expect($reloaded)->not->toBeNull()->and($reloaded->trashed())->toBeTrue();
});

it('retries and finishes cleanly after a storage failure is resolved', function () {
    $asset = createPurgeableAsset();
    $failingVariantPath = $asset->variants()->first()->path;

    $failingStorage = new SelectiveDeleteFailureStorage(app(MediaStorage::class), $failingVariantPath);
    $lifecycle = new MediaLifecycleService(app(MediaReferenceChecker::class), $failingStorage);

    $firstAttempt = $lifecycle->purge($asset);
    expect($firstAttempt->outcome)->toBe(MediaPurgeOutcome::Failed);

    // Retry with the real, non-failing storage — idempotent: the variants
    // that already had their files deleted before the failure just no-op.
    $secondAttempt = app(MediaLifecycleService::class)->purge($asset->fresh());

    expect($secondAttempt->outcome)->toBe(MediaPurgeOutcome::Purged);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('is a recoverable, retryable state when the DB force-delete fails after every file has already been removed', function () {
    $asset = createPurgeableAsset();
    $masterPath = $asset->path;
    $variantPaths = $asset->variants()->pluck('path')->all();

    MediaAsset::deleting(function (): void {
        throw new RuntimeException('Simulated DB failure.');
    });

    $result = null;

    try {
        $result = app(MediaLifecycleService::class)->purge($asset);
    } finally {
        MediaAsset::flushEventListeners();
    }

    expect($result->outcome)->toBe(MediaPurgeOutcome::Failed);

    // Files are already gone...
    Storage::disk('public')->assertMissing($masterPath);
    foreach ($variantPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    // ...but the row remains, still trashed, ready for retry.
    $reloaded = MediaAsset::withTrashed()->find($asset->id);
    expect($reloaded)->not->toBeNull()->and($reloaded->trashed())->toBeTrue();

    // A clean retry (no failing listener this time) finishes the job: every
    // remaining physical delete is a no-op (already gone), only the DB step
    // actually does anything.
    $retry = app(MediaLifecycleService::class)->purge($asset->fresh());
    expect($retry->outcome)->toBe(MediaPurgeOutcome::Purged);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('skips an asset whose purge lock is already held by another process, deleting nothing', function () {
    $asset = createPurgeableAsset();
    $masterPath = $asset->path;

    $externalLock = Cache::store('database')->lock("media-purge:{$asset->id}", 10);
    expect($externalLock->get())->toBeTrue();

    try {
        $result = null;
        $exception = null;

        // A failed lock-acquire attempt under the `database` store means a
        // failed unique-key INSERT — under PostgreSQL specifically, that
        // marks the *whole* current transaction as aborted, not just that
        // one statement, and Laravel's own insert-then-fall-back-to-update
        // retry inside DatabaseLock::acquire() then hits that same aborted
        // transaction on its very next statement, surfacing as a raw
        // QueryException rather than a clean `false` return. Running the
        // attempt inside its own nested DB::transaction() turns the whole
        // sequence into a savepoint (same recovery technique as
        // MediaVariantWriterTest's own lock-contention test): if it throws,
        // the ROLLBACK TO SAVEPOINT Laravel issues on the way out still
        // leaves the rest of this test able to issue queries normally
        // afterward, and either a thrown exception or a clean `Locked`
        // result is equally valid proof the attempt did not proceed — the
        // real invariant under test is that nothing got deleted.
        try {
            DB::transaction(function () use ($asset, &$result): void {
                $result = app(MediaLifecycleService::class)->purge($asset);
            });
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        expect($exception !== null || $result?->outcome === MediaPurgeOutcome::Locked)->toBeTrue();
        Storage::disk('public')->assertExists($masterPath);
        expect(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
    } finally {
        $externalLock->release();
    }

    // Lock released: purge now proceeds normally.
    $result = app(MediaLifecycleService::class)->purge($asset->fresh());
    expect($result->outcome)->toBe(MediaPurgeOutcome::Purged);
});

it('is idempotent: purging an already-purged asset again is a safe, no-op success', function () {
    $asset = createPurgeableAsset();

    $first = app(MediaLifecycleService::class)->purge($asset);
    expect($first->outcome)->toBe(MediaPurgeOutcome::Purged);

    $second = app(MediaLifecycleService::class)->purge($asset);
    expect($second->outcome)->toBe(MediaPurgeOutcome::AlreadyGone);
});
