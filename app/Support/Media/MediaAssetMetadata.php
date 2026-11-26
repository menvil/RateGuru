<?php

namespace App\Support\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/**
 * Changes keys of an asset's metadata without losing anyone else's. The
 * column is one JSON value that several writers keep their own keys in — the
 * processor, the import, the sweeper's markers, the author notices — so each
 * reads it under a lock on the row and writes it back in the same
 * transaction: two writers meeting on one asset take turns, and neither
 * writes back a copy older than the other's key.
 *
 * It never moves updated_at: a marker is bookkeeping about the asset, not a
 * change to it, and the sweeps read updated_at as the time an upload failed.
 */
final class MediaAssetMetadata
{
    /**
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $change  the metadata as it is now, to the
     *                                                                         metadata it should be — or null to leave it
     * @return bool whether it was changed
     */
    public static function change(int $assetId, callable $change): bool
    {
        return DB::transaction(function () use ($assetId, $change): bool {
            $row = MediaAsset::withTrashed()->whereKey($assetId)->lockForUpdate()->first(['id', 'metadata']);

            if ($row === null) {
                return false;
            }

            $changed = $change($row->metadata ?? []);

            if ($changed === null) {
                return false;
            }

            MediaAsset::withTrashed()->whereKey($assetId)->toBase()->update(['metadata' => json_encode($changed)]);

            return true;
        });
    }
}
