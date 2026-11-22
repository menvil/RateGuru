<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockNetworkState;
use App\Models\TrafficBlock;
use App\Models\TrafficVisit;

/**
 * A click from a site or zone that was blocked more than an hour ago, through
 * a campaign whose list holds the block as far as RateGuru knows: the
 * network is still sending it. A campaign that refused the block, or had it
 * taken off in the network's panel, sends it as it should. The visit is
 * marked, every block it matches counts it — its site's and its zone's both,
 * in the database itself, so clicks counted at once never overwrite each
 * other; an applied block with a growing count is worth pushing again — and
 * the click is counted and paid for like any other.
 */
final class RecordBlockedHitAction
{
    private const int GRACE_MINUTES = 60;

    public function handle(TrafficVisit $visit): void
    {
        if ($visit->source_id === null || ($visit->site_ref === null && $visit->zone_ref === null)) {
            return;
        }

        $hits = TrafficBlock::query()
            ->where('source_id', $visit->source_id)
            ->whereIn('network_state', [TrafficBlockNetworkState::ByHand, TrafficBlockNetworkState::Applied, TrafficBlockNetworkState::Partial])
            ->where('blocked_at', '<', $visit->arrived_at->subMinutes(self::GRACE_MINUTES))
            ->where(fn ($match) => $match
                ->when($visit->zone_ref !== null, fn ($zone) => $zone->orWhere(fn ($query) => $query->where('level', TrafficBlockLevel::Zone)->where('ref', $visit->zone_ref)))
                ->when($visit->site_ref !== null, fn ($site) => $site->orWhere(fn ($query) => $query->where('level', TrafficBlockLevel::Site)->where('ref', $visit->site_ref))))
            ->where(fn ($campaign) => $campaign->whereNull('campaign_id')->orWhere('campaign_id', $visit->campaign_id))
            // A campaign whose list is known not to hold the block sends its clicks as it should.
            ->when($visit->campaign_id !== null, fn ($query) => $query->whereDoesntHave('listings', fn ($listing) => $listing->where('campaign_id', $visit->campaign_id)->where('listed', false)))
            ->increment('clicks_after', 1, ['last_click_after_at' => $visit->arrived_at]);

        if ($hits > 0) {
            TrafficVisit::query()->whereKey($visit->id)->update(['blocked_hit' => true]);
        }
    }
}
