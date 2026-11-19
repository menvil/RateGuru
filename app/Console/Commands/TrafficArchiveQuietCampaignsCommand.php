<?php

namespace App\Console\Commands;

use App\Actions\Traffic\RecordTrafficCampaignEventAction;
use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Enums\TrafficCampaignStatus;
use App\Models\TrafficCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Archives the campaigns no click has come through for a while
 * (`traffic.quiet_campaign_days`), so the lists show what runs. Their visits
 * and spend stay in every report, and a click later brings one back.
 */
final class TrafficArchiveQuietCampaignsCommand extends Command
{
    protected $signature = 'traffic:archive-quiet-campaigns';

    protected $description = 'Archive the traffic campaigns no click has come through for a while.';

    public function handle(RecordTrafficCampaignEventAction $history): int
    {
        $days = (int) config('traffic.quiet_campaign_days', 30);
        $cutoff = now()->subDays($days);
        $archived = 0;

        TrafficCampaign::query()
            ->where('status', '!=', TrafficCampaignStatus::Archived)
            ->where('first_click_at', '<', $cutoff)
            ->whereDoesntHave('visits', fn ($visits) => $visits->where('arrived_at', '>=', $cutoff))
            ->orderBy('id')
            ->chunkById(200, function ($campaigns) use ($history, $days, &$archived): void {
                foreach ($campaigns as $campaign) {
                    DB::transaction(function () use ($campaign, $history, $days, &$archived): void {
                        // Only as it was picked: a campaign saved since is left to the next run.
                        $moved = TrafficCampaign::query()->whereKey($campaign->id)->where('status', '!=', TrafficCampaignStatus::Archived)->where('lock_version', $campaign->lock_version)->update([
                            'status_before_archive' => $campaign->status,
                            'status' => TrafficCampaignStatus::Archived,
                            'archived_at' => now(),
                            'archived_automatically' => true,
                            'lock_version' => $campaign->lock_version + 1,
                        ]);

                        if ($moved === 1) {
                            $history->handle((int) $campaign->id, TrafficCampaignEventKind::Archive, "Archived: no clicks for {$days} days", TrafficActor::System);
                            $archived++;
                        }
                    });
                }
            });

        $this->info("Archived {$archived} quiet campaigns.");

        return self::SUCCESS;
    }
}
