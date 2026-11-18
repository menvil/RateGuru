<?php

namespace App\Actions\Analytics;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficSegment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Undo after a segment was deleted: it is back on its source's card, its
 * name and conditions as they were — while the source holds fewer than
 * SaveTrafficSegmentAction::MOST_SEGMENTS. Bringing back one that isn't
 * deleted changes nothing.
 */
final class RestoreTrafficSegmentAction
{
    use LocksTrafficRecords;

    /** @throws CannotManageTrafficException */
    public function handle(User $admin, TrafficSegment $segment): TrafficSegment
    {
        return DB::transaction(function () use ($admin, $segment): TrafficSegment {
            $locked = $this->lockAdministrator($admin);
            $record = $this->lockSegment((int) $segment->id, deleted: true);

            if (! $record->trashed()) {
                return $record;
            }

            if (TrafficSegment::query()->where('source_id', $record->source_id)->count() >= SaveTrafficSegmentAction::MOST_SEGMENTS) {
                throw CannotManageTrafficException::because('Its source already has '.SaveTrafficSegmentAction::MOST_SEGMENTS.' segments, the most a source holds, so it stays deleted.');
            }

            $record->forceFill([
                'deleted_at' => null,
                'updated_by_user_id' => $locked->id,
                'lock_version' => $record->lock_version + 1,
            ])->save();

            return $record;
        });
    }
}
