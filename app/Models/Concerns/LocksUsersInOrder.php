<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Deterministic multi-user locking (docs/architecture/user-lifecycle.md).
 * One row per query, strictly ascending by primary key: a single
 * whereIn + ORDER BY + FOR UPDATE leaves lock acquisition order to the
 * query plan, not the ORDER BY, so it only reduces deadlock risk when
 * every transaction happens to use the same access path. Locking each row
 * separately in sorted order formally guarantees every code path acquires
 * user locks in the same global order.
 */
trait LocksUsersInOrder
{
    /** @return Collection<int, User> locked rows keyed by id; missing rows are absent */
    private function lockUsersInOrder(int ...$ids): Collection
    {
        $ids = array_unique($ids);
        sort($ids);

        $locked = new Collection;

        foreach ($ids as $id) {
            $row = User::query()->whereKey($id)->lockForUpdate()->first();

            if ($row !== null) {
                $locked->put($id, $row);
            }
        }

        return $locked;
    }
}
