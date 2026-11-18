<?php

namespace App\Actions\Analytics;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficSegment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a paid source's segment from its drawer: it is only marked, its
 * name and conditions kept, so Undo (RestoreTrafficSegmentAction) brings it
 * back as it was. Only at the version the drawer was drawn from, when it
 * names one; deleting one already deleted changes nothing.
 */
final class DeleteTrafficSegmentAction
{
    use LocksTrafficRecords;

    /** @throws CannotManageTrafficException */
    public function handle(User $admin, TrafficSegment $segment, ?int $lockVersion = null): TrafficSegment
    {
        return DB::transaction(function () use ($admin, $segment, $lockVersion): TrafficSegment {
            $locked = $this->lockAdministrator($admin);
            $record = $this->lockSegment((int) $segment->id, deleted: true);

            if ($record->trashed()) {
                return $record;
            }

            if ($lockVersion !== null && $record->lock_version !== $lockVersion) {
                throw CannotManageTrafficException::becauseItChanged('this segment');
            }

            $record->forceFill([
                'deleted_at' => now(),
                'updated_by_user_id' => $locked->id,
                'lock_version' => $record->lock_version + 1,
            ])->save();

            return $record;
        });
    }
}
