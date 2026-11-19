<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use App\Models\TrafficCampaignEvent;

/** One line of a campaign's history: what changed, said for the administrator, and who changed it. */
final class RecordTrafficCampaignEventAction
{
    public function handle(int $campaignId, TrafficCampaignEventKind $kind, string $summary, TrafficActor $actor, ?int $actorUserId = null): void
    {
        TrafficCampaignEvent::query()->create([
            'campaign_id' => $campaignId,
            'kind' => $kind,
            'summary' => mb_substr($summary, 0, 300),
            'actor' => $actor,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ]);
    }
}
