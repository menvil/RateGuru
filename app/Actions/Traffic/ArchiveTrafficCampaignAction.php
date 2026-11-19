<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCampaignStatus;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficCampaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes a campaign out of the lists, or puts it back as it was. Archiving
 * hides nothing it brought — its visits and spend stay in every report —
 * and a click on it later brings it back by itself (RecordPaidClickAction).
 */
final class ArchiveTrafficCampaignAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly RecordTrafficCampaignEventAction $history) {}

    /** @throws CannotManageTrafficException */
    public function archive(User $admin, TrafficCampaign $campaign): TrafficCampaign
    {
        return DB::transaction(function () use ($admin, $campaign): TrafficCampaign {
            $locked = $this->lockAdministrator($admin);
            $record = $this->lockCampaign((int) $campaign->id);

            if ($record->status === TrafficCampaignStatus::Archived) {
                return $record;
            }

            $record->forceFill([
                'status_before_archive' => $record->status,
                'status' => TrafficCampaignStatus::Archived,
                'archived_at' => now(),
                'archived_by_user_id' => $locked->id,
                'archived_automatically' => false,
                'lock_version' => $record->lock_version + 1,
            ])->save();

            $this->history->handle((int) $record->id, TrafficCampaignEventKind::Archive, 'Archived', TrafficActor::User, (int) $locked->id);

            return $record;
        });
    }

    /** @throws CannotManageTrafficException */
    public function restore(User $admin, TrafficCampaign $campaign): TrafficCampaign
    {
        return DB::transaction(function () use ($admin, $campaign): TrafficCampaign {
            $locked = $this->lockAdministrator($admin);
            $record = $this->lockCampaign((int) $campaign->id);

            if ($record->status !== TrafficCampaignStatus::Archived) {
                return $record;
            }

            $record->forceFill([
                'status' => $record->status_before_archive ?? TrafficCampaignStatus::New,
                'status_before_archive' => null,
                'archived_at' => null,
                'archived_by_user_id' => null,
                'archived_automatically' => false,
                'lock_version' => $record->lock_version + 1,
            ])->save();

            $this->history->handle((int) $record->id, TrafficCampaignEventKind::Archive, 'Restored from the archive', TrafficActor::User, (int) $locked->id);

            return $record;
        });
    }
}
