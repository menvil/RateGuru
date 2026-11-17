<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnectorFailure;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficCampaign;
use App\Services\Traffic\Connectors\ConnectorException;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\ConnectorSession;
use App\Services\Traffic\Connectors\EditsBlockLists;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Applies the blocks waiting for a source's network — to the campaign each
 * names, or every campaign of the source not archived — one change per
 * campaign with all its sites or zones, and takes back the ones being
 * unblocked. Applied when every campaign took it; failed with the reason
 * otherwise (`network_error` keeps the TrafficConnectorFailure, or
 * `no_campaign`). One line of the log per batch, with the HTTP code and how many
 * campaigns took it. Safe to repeat: each change reads the list first.
 */
final class ApplyTrafficBlocksAction
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function handle(TrafficApiConnection $connection): void
    {
        $blocks = TrafficBlock::query()->where('source_id', $connection->source_id)->whereIn('network_state', [TrafficBlockNetworkState::Pending, TrafficBlockNetworkState::Removing])->orderBy('id')->get();

        if ($blocks->isEmpty() || $connection->status !== TrafficConnectionStatus::Connected) {
            return;
        }

        $connector = $this->registry->connector($connection->connector);

        if (! $connector instanceof EditsBlockLists) {
            return;
        }

        try {
            $session = new ConnectorSession($connection->credentials ?? []);
        } catch (DecryptException) {
            return;
        }

        $campaigns = TrafficCampaign::query()->where('source_id', $connection->source_id)->where('status', '!=', TrafficCampaignStatus::Archived)->pluck('external_id', 'id')->all();

        foreach ($blocks->groupBy(fn (TrafficBlock $block): string => $block->level->value.'|'.($block->campaign_id ?? '*')) as $group) {
            $first = $group->first();
            $targets = $first->campaign_id === null ? $campaigns : array_intersect_key($campaigns, [$first->campaign_id => true]);
            $add = $group->where('network_state', TrafficBlockNetworkState::Pending)->pluck('ref')->map(fn ($ref): string => (string) $ref)->values()->all();
            $remove = $group->where('network_state', TrafficBlockNetworkState::Removing)->pluck('ref')->map(fn ($ref): string => (string) $ref)->values()->all();
            $done = 0;
            $status = null;
            $error = null;

            foreach ($targets as $externalId) {
                try {
                    $result = $connector->applyBlockDelta($session, (string) $externalId, $first->level, $add, $remove);
                    $status = $result->httpStatus;
                    $done += $result->applied ? 1 : 0;
                    $error = $result->applied ? $error : TrafficConnectorFailure::Rejected->value;
                } catch (ConnectorException $exception) {
                    $status = $exception->httpStatus;
                    $error = $exception->failure->value;
                }
            }

            $ok = $targets !== [] && $done === count($targets);

            foreach ($group as $block) {
                if ($block->network_state === TrafficBlockNetworkState::Removing) {
                    $ok ? $block->delete() : $block->forceFill(['network_state' => TrafficBlockNetworkState::Applied, 'network_error' => $error])->save();
                } else {
                    $block->forceFill(['network_state' => $ok ? TrafficBlockNetworkState::Applied : TrafficBlockNetworkState::Failed, 'network_error' => $ok ? null : ($error ?? 'no_campaign')])->save();
                }
            }

            TrafficBlockEvent::query()->create([
                'source_id' => $connection->source_id,
                'kind' => $remove !== [] && $add === [] ? 'removed' : 'applied',
                'level' => $first->level,
                'ref' => $group->count() === 1 ? $first->ref : null,
                'ref_count' => $group->count(),
                'refs' => $group->pluck('ref')->take(200)->values()->all(),
                'campaign_id' => $first->campaign_id,
                'method' => TrafficBlockMethod::Api,
                'actor' => TrafficActor::NetworkApi,
                'ok' => $ok,
                'http_status' => $status,
                'note' => $done.' of '.count($targets).' campaigns',
                'occurred_at' => now(),
            ]);

            if ($session->changed()) {
                $connection->forceFill(['credentials' => $session->credentials()])->save();
            }
        }
    }
}
