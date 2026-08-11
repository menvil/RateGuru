<?php

namespace App\Services\Media;

use App\Enums\MediaPurgeOutcome;
use App\Models\MediaAsset;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The single owner of every MediaAsset/MediaVariant delete decision and
 * action. Nothing else in the app force-deletes a MediaAsset row or purges
 * its physical files — StoredMediaCleaner and
 * MediaVariantWriter::deleteQuietly() remain separate, pre-existing,
 * narrowly-scoped create-flow compensation (deleting a just-written file
 * after a DB failure moments earlier), not part of this lifecycle.
 *
 * Soft-delete (an asset becoming a "release candidate") happens elsewhere —
 * avatar replacement already soft-deletes the previous asset inline
 * (UpdateUserProfileAction, unchanged by this class), and
 * releaseUnreferenced() is the hook DeleteUserAccountAction uses once a
 * user's posts/avatar have just become unreferenced. This class only ever
 * decides/executes what happens *after* an asset is already soft-deleted:
 * whether it's purgeable yet, and performing the purge itself.
 */
final class MediaLifecycleService
{
    public function __construct(
        private readonly MediaReferenceChecker $referenceChecker,
        private readonly MediaStorage $storage,
    ) {}

    /**
     * Pure decision, no lock, no I/O beyond the reference check's own
     * queries — safe to call repeatedly for reporting (audit, --dry-run).
     */
    public function isPurgeable(MediaAsset $asset, ?int $graceDays = null): bool
    {
        if (! $this->isGraceExpired($asset, $graceDays)) {
            return false;
        }

        return ! $this->referenceChecker->isReferenced($asset);
    }

    /**
     * The trashed/grace-period half of isPurgeable(), without also querying
     * references — exposed separately for batch reporting callers
     * (media:audit) that have already resolved a referenced-id set in bulk
     * to avoid issuing MediaReferenceChecker's two queries per asset.
     */
    public function isGraceExpired(MediaAsset $asset, ?int $graceDays = null): bool
    {
        // Resolved (and validated) unconditionally, before the trashed()
        // check — an invalid graceDays argument is a programmer error that
        // must surface the same way regardless of the asset's incidental
        // state. Checking trashed() first would let a negative override
        // silently slip through for an active asset (early return before
        // resolveGraceDays() ever runs), while the exact same bad argument
        // would throw for a trashed one — a caller-observable inconsistency
        // that depends on nothing but which asset happened to be passed in.
        $cutoff = now()->subDays($this->resolveGraceDays($graceDays));

        if (! $asset->trashed()) {
            return false;
        }

        return $asset->deleted_at !== null && $asset->deleted_at->lte($cutoff);
    }

    /**
     * A negative override would make the cutoff a future date, defeating
     * the grace period entirely (every trashed asset, however recently
     * deleted, would immediately read as grace-expired) — the CLI already
     * rejects a negative --older-than before it gets here, but this is a
     * public service method other callers could reach directly, so the
     * invariant is enforced at the boundary rather than trusted from
     * outside. config('media.lifecycle.purge_grace_days') is already
     * clamped non-negative at the config layer, so only an explicit
     * argument can trigger this.
     */
    private function resolveGraceDays(?int $graceDays): int
    {
        $days = $graceDays ?? (int) config('media.lifecycle.purge_grace_days');

        if ($days < 0) {
            throw new InvalidArgumentException("graceDays must not be negative, got [{$days}].");
        }

        return $days;
    }

    /**
     * Reloads and re-verifies everything under a per-asset lock before
     * touching anything — the caller's own $asset may be stale (e.g. read
     * moments ago by a chunked query in media:purge, while a concurrent
     * process already purged or restored it). All physical file I/O happens
     * before the one-statement DB transaction, which force-deletes only the
     * asset row: media_variants.media_asset_id has ->cascadeOnDelete() at
     * the DB level, so the variant rows disappear as part of that same
     * statement — no need to re-issue an explicit MediaVariant delete.
     *
     * The lock is a single non-blocking attempt (Lock::get()), not
     * MediaVariantWriter's blocking Lock::block(): this runs as a chunked
     * batch sweep over many assets, and blocking on one contended asset
     * would stall the whole run. A failed attempt just means "skip this
     * asset this pass" — purge reruns routinely, and eligibility, once
     * earned, never expires.
     */
    public function purge(MediaAsset $asset, ?int $graceDays = null): MediaPurgeResult
    {
        // Validated before any lock/DB work at all — the same "programmer
        // error, not asset-state-dependent" reasoning as isGraceExpired()
        // above: an invalid override should fail the same way whether the
        // asset is active, trashed, or already gone, not only when it
        // happens to still be around long enough to reach isPurgeable().
        $this->resolveGraceDays($graceDays);

        $lock = $this->purgeLock($asset->id);

        if (! $lock->get()) {
            return MediaPurgeResult::skipped($asset->id, MediaPurgeOutcome::Locked);
        }

        try {
            $fresh = MediaAsset::withTrashed()->with('variants')->find($asset->id);

            if ($fresh === null) {
                return MediaPurgeResult::skipped($asset->id, MediaPurgeOutcome::AlreadyGone);
            }

            if (! $this->isPurgeable($fresh, $graceDays)) {
                return MediaPurgeResult::skipped($fresh->id, MediaPurgeOutcome::NotEligible);
            }

            foreach ($fresh->variants as $variant) {
                try {
                    $this->storage->deleteIfExists(new MediaLocation($variant->disk, $variant->path));
                } catch (Throwable $exception) {
                    Log::error('MediaLifecycleService: variant file delete failed.', [
                        'media_asset_id' => $fresh->id,
                        'media_variant_id' => $variant->id,
                        'disk' => $variant->disk,
                        'path' => $variant->path,
                        'operation' => 'purge_variant',
                        'exception_class' => $exception::class,
                    ]);

                    return MediaPurgeResult::failed($fresh->id, $exception);
                }
            }

            try {
                $this->storage->deleteIfExists(new MediaLocation($fresh->disk, $fresh->path));
            } catch (Throwable $exception) {
                Log::error('MediaLifecycleService: master file delete failed.', [
                    'media_asset_id' => $fresh->id,
                    'disk' => $fresh->disk,
                    'path' => $fresh->path,
                    'operation' => 'purge_master',
                    'exception_class' => $exception::class,
                ]);

                return MediaPurgeResult::failed($fresh->id, $exception);
            }

            try {
                DB::transaction(fn () => $fresh->forceDelete());
            } catch (Throwable $exception) {
                // Recoverable: the physical files are already gone, the row
                // remains trashed. A subsequent purge() re-attempts this
                // exact asset — deleteIfExists() above no-ops for the
                // already-missing files, and only the DB step is retried.
                Log::error('MediaLifecycleService: DB force-delete failed after file removal.', [
                    'media_asset_id' => $fresh->id,
                    'operation' => 'purge_force_delete',
                    'exception_class' => $exception::class,
                ]);

                return MediaPurgeResult::failed($fresh->id, $exception);
            }

            return MediaPurgeResult::purged($fresh->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * The hook DeleteUserAccountAction calls once, with every asset id that
     * may have just become unreferenced by a hard user delete (their
     * avatar, their posts' images — the posts themselves are already gone
     * by the time this runs). One reference-check pass, via
     * MediaReferenceChecker::referencedAssetIds(), instead of one per
     * asset id, then a single bulk soft-delete for whichever ids are still
     * unreferenced — the same batching MediaAuditCommand already uses, and
     * for the same reason: a user with many posts would otherwise cost up
     * to 4 queries per asset here. The bulk update still only ever touches
     * currently-active rows (Eloquent's own SoftDeletingScope excludes
     * already-trashed ones automatically, exactly as MediaAsset::find()
     * did for the single-asset version this replaces), so it can never
     * reset an already-soft-deleted asset's grace-period clock.
     *
     * Deliberately *not* best-effort: DeleteUserAccountAction runs this
     * inside the same DB transaction as the user delete itself, so a
     * failure here must propagate and roll the whole transaction back —
     * swallowing it here would let the user delete commit while its media
     * silently stayed active-and-unreferenced, with no further hook to
     * ever soft-delete it (media:purge's sweep only considers already-
     * trashed rows). Soft-deletes only — physical purge still waits for
     * the grace period via the normal media:purge sweep.
     *
     * @param  Collection<int, int>  $assetIds
     */
    public function releaseUnreferenced(Collection $assetIds): void
    {
        // No filter() here: the parameter type already contracts a plain
        // Collection<int, int> (no nulls), the one caller (DeleteUserAccountAction)
        // already filters nulls out before this is reached, and an id of 0
        // can't occur (MediaAsset ids auto-increment from 1). unique()/
        // values() alone don't narrow PHPStan's inferred value type the way
        // an exclusionary filter()/reject() predicate would — which matters
        // here, since Collection's generics are invariant and
        // referencedAssetIds() below declares a plain `int` value type.
        $assetIds = $assetIds->unique()->values();

        if ($assetIds->isEmpty()) {
            return;
        }

        $referencedIds = $this->referenceChecker->referencedAssetIds($assetIds);
        $unreferencedIds = $assetIds->reject(fn (int $id): bool => $referencedIds->has($id))->values();

        if ($unreferencedIds->isNotEmpty()) {
            MediaAsset::whereIn('id', $unreferencedIds)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Deletes a physical file that has no matching MediaAsset/MediaVariant
     * row at all (a true physical orphan, found by MediaOrphanScanner) — no
     * DB row is involved by definition, so there's nothing else to do.
     */
    public function purgeOrphanFile(MediaLocation $location): void
    {
        $this->storage->deleteIfExists($location);
    }

    /**
     * Same Cache::store('database')/LockProvider pattern as
     * MediaVariantWriter::variantWriteLock() — see that class's docblock for
     * why `database`, not Redis or config('cache.default').
     */
    private function purgeLock(int $assetId): Lock
    {
        $store = Cache::store('database')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The "database" cache store does not support locks.');
        }

        return $store->lock("media-purge:{$assetId}", (int) config('media.lifecycle.purge_lock.ttl_seconds'));
    }
}
