<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves a campaign to a stage — test, optimise, scale or paused — at once:
 * the stage it was in ends now, the new one starts, and a campaign that
 * came in by itself becomes one the administrator has set up. The stages
 * are periods, so a report can compare one with the next.
 */
final class ChangeTrafficCampaignStageAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly RecordTrafficCampaignEventAction $history) {}

    /** @throws CannotManageTrafficException */
    public function handle(User $admin, TrafficCampaign $campaign, TrafficCampaignStage $stage): TrafficCampaign
    {
        return DB::transaction(function () use ($admin, $campaign, $stage): TrafficCampaign {
            $locked = $this->lockAdministrator($admin);
            $record = $this->lockCampaign((int) $campaign->id);

            if ($record->status === TrafficCampaignStatus::Archived) {
                throw CannotManageTrafficException::because('This campaign is archived. Restore it first.');
            }

            if ($record->stage === $stage) {
                return $record;
            }

            TrafficCampaignStagePeriod::query()->where('campaign_id', $record->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            TrafficCampaignStagePeriod::query()->create(['campaign_id' => $record->id, 'stage' => $stage, 'started_at' => now()]);
            $from = $record->stage;

            $record->forceFill([
                'stage' => $stage,
                'status' => TrafficCampaignStatus::Active,
                'set_up_at' => $record->set_up_at ?? now(),
                'lock_version' => $record->lock_version + 1,
            ])->save();

            $this->history->handle((int) $record->id, TrafficCampaignEventKind::Stage, $from === null ? 'Stage set to '.ucfirst($stage->value) : 'Stage changed from '.ucfirst($from->value).' to '.ucfirst($stage->value), TrafficActor::User, (int) $locked->id);

            return $record;
        });
    }
}
