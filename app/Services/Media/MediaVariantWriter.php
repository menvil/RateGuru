<?php

namespace App\Services\Media;

use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Generates one variant and persists it (file + row). No atomic move
 * primitive exists on MediaStorage, so ordering is best-effort, not
 * transactional: generate/encode/validate fully in memory first (the
 * failure-prone part), then write the file, then upsert the row.
 *
 * The write/upsert/cleanup sequence for a given (asset, variant name) runs
 * behind a lock (config('media.variant_lock')) — without it, two workers
 * regenerating the same variant concurrently (e.g. a queued retry racing a
 * manual `media:generate-variants --force` run) could interleave: worker
 * A's file write, worker B's file write + successful DB commit, then worker
 * A's DB failure triggering a cleanup that deletes the file B just
 * committed a row for. The lock makes the two attempts fully sequential
 * instead of merely reducing the race window.
 *
 * The lock is explicitly pinned to the `database` cache store rather than
 * using whatever config('cache.default') happens to be (`file` in this
 * app's .env). A `file`-store lock is only a real mutex for processes that
 * share one local filesystem — true today (each deployment target is a
 * single server), but silently wrong the moment a target is ever split
 * across multiple app servers. The `database` store uses this app's one
 * shared Postgres connection instead, which every process already depends
 * on regardless of server topology, so it holds the same guarantee whether
 * or not that ever changes. It costs nothing extra to set up: the `cache`
 * and `cache_locks` tables are part of the base migration set already
 * present in every environment. (Not Redis — see AGENTS.md's "forbidden
 * without a separate task" list.)
 *
 * On a first-time write (no prior row for this asset+name), a DB failure
 * after the file write deletes the now-orphaned file. On a regeneration (a
 * row already existed), a DB failure after the overwrite does *not* delete
 * the file — the previously-working file has already been replaced, so
 * deleting it would leave nothing; the row's metadata may be transiently
 * stale until a successful retry, which is preferred over destroying a
 * working variant.
 *
 * A regeneration can also move a variant: when its format changes, so does
 * its file extension and with it the path. The previous file then stays the
 * one the row names until the row is updated, so a DB failure deletes the
 * new file instead, and a success deletes the previous one, which nothing
 * claims any more.
 */
final class MediaVariantWriter
{
    public function __construct(
        private readonly ImageVariantProcessor $processor,
        private readonly MediaVariantPathGenerator $pathGenerator,
        private readonly MediaStorage $storage,
    ) {}

    /**
     * $sourceMimeType is what $masterBytes are when they are not the asset's
     * own file — a video's poster frame, a PNG, for an asset whose mime type
     * is video/mp4.
     */
    public function write(MediaAsset $asset, string $masterBytes, MediaVariantSpecification $specification, ?string $sourceMimeType = null): MediaVariant
    {
        $generated = $this->processor->generate($masterBytes, $sourceMimeType ?? $asset->mime_type, $specification);

        return $this->persist(
            $asset,
            $specification->name,
            $generated->extension,
            fn (MediaLocation $location) => $this->storage->putContents($location, $generated->bytes, $asset->visibility),
            [
                'mime_type' => $generated->mimeType,
                'extension' => $generated->extension,
                'byte_size' => $generated->byteSize,
                'width' => $generated->width,
                'height' => $generated->height,
            ],
        );
    }

    /**
     * Persists a variant a tool already produced as a local file — a video
     * rendition ffmpeg wrote, too large to hold in memory — under the same
     * lock, path and failure cleanup as write(). The file is streamed to
     * storage; the caller owns the local file and removes it.
     */
    public function writeFile(MediaAsset $asset, MediaVariantName $name, ProducedMediaFile $file): MediaVariant
    {
        return $this->persist(
            $asset,
            $name,
            $file->extension,
            fn (MediaLocation $location) => $this->storage->putFile($location, $file->localPath, $asset->visibility),
            [
                'mime_type' => $file->mimeType,
                'extension' => $file->extension,
                'byte_size' => $file->byteSize,
                'width' => $file->width,
                'height' => $file->height,
                'duration_ms' => $file->durationMs,
            ],
        );
    }

    /**
     * The write/upsert/cleanup sequence both kinds of variant share.
     *
     * @param  Closure(MediaLocation): void  $store
     * @param  array<string, mixed>  $attributes
     */
    private function persist(MediaAsset $asset, MediaVariantName $name, string $extension, Closure $store, array $attributes): MediaVariant
    {
        $path = $this->pathGenerator->generate($asset, $name, $extension);
        $location = new MediaLocation($asset->disk, $path);

        $lockKey = "media-variant-write:{$asset->id}:{$name->value}";
        $lock = $this->variantWriteLock($lockKey);

        return $lock->block(
            (int) config('media.variant_lock.wait_seconds'),
            function () use ($asset, $name, $path, $location, $store, $attributes): MediaVariant {
                $previous = MediaVariant::query()
                    ->where('media_asset_id', $asset->id)
                    ->where('name', $name)
                    ->first();
                $moved = $previous !== null && ($previous->disk !== $asset->disk || $previous->path !== $path);

                $store($location);

                try {
                    $variant = MediaVariant::query()->updateOrCreate(
                        ['media_asset_id' => $asset->id, 'name' => $name],
                        ['disk' => $asset->disk, 'path' => $path, ...$attributes],
                    );
                } catch (Throwable $exception) {
                    if ($previous === null || $moved) {
                        $this->deleteQuietly($location);
                    }

                    throw $exception;
                }

                if ($moved) {
                    $this->deleteQuietly(new MediaLocation($previous->disk, $previous->path));
                }

                return $variant;
            },
        );
    }

    /**
     * `Cache::store('database')` is typed as the generic
     * Illuminate\Contracts\Cache\Repository, which doesn't declare lock() —
     * only the underlying Store does, for stores that support it
     * (LockProvider). Going through getStore() and narrowing with instanceof
     * is the same pattern Repository::funnel() itself uses internally.
     */
    private function variantWriteLock(string $key): Lock
    {
        $store = Cache::store('database')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The "database" cache store does not support locks.');
        }

        return $store->lock($key, (int) config('media.variant_lock.ttl_seconds'));
    }

    /**
     * Best-effort, same shape as StoredMediaCleaner::deleteIfUnclaimed():
     * skips deletion when another MediaVariant row now claims this exact
     * (disk, path). The write lock above already makes a same-key race
     * against another write() call impossible; this check is a second,
     * cheap layer of defense for anything the lock doesn't cover (e.g. a
     * lock TTL elapsing under an abnormally slow write, or the row being
     * created through some other path entirely) — belt and suspenders, not
     * the primary guard.
     */
    private function deleteQuietly(MediaLocation $location): void
    {
        try {
            $claimed = MediaVariant::query()
                ->where('disk', $location->disk)
                ->where('path', $location->path)
                ->exists();

            if ($claimed) {
                return;
            }

            $this->storage->delete($location);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
