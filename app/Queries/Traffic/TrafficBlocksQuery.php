<?php

namespace App\Queries\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectorFailure;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficChanceRun;
use App\Models\TrafficSource;
use App\Models\TrafficStepChance;
use App\Support\Traffic\Blocking\BlockStanding;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * What the block rule (traffic:block-losers) and a source's Blocking pane
 * read about blocking, so both count it alike: a source's blocks by level,
 * by who made them and by where they stand with the network — the states
 * Sites & zones' badges and Logs → Blocks say too — in one query;
 * how many the rule queued in the UTC day its cap counts, from its own
 * entries in the blocks log, so a block taken off again still counts; the
 * sites and zones an administrator unblocked lately — in RateGuru or in the
 * network's own panel — which the rule leaves alone; and how many accounts
 * the worth stands on, which decides whether losing is judged at all; and
 * the running campaigns RateGuru's own blocks are still to be sent to.
 */
final class TrafficBlocksQuery
{
    /** Losing is judged only once the worth stands on this many accounts. */
    public const int WORTH_KNOWN_AT = 30;

    /** A site or zone an administrator unblocked — in RateGuru, or in the network's panel — is left to them for as long as the rule's figures reach back. */
    public const int UNBLOCKED_KEPT_DAYS = 30;

    /**
     * A source's blocks: those in force by level and by who made them — an
     * administrator, by hand or through the API, the rule, or an
     * administrator in the network's own panel — and every block by where it
     * stands with the network, those sent by RateGuru on every list they are
     * for, on some only, waiting, on none, taken off them in the panel or
     * coming off; and how many the rule queued today. Two queries, whatever
     * the number of blocks.
     *
     * @return array{sites: int, zones: int, by_hand: int, by_rule: int, in_panel: int, marked: int, pending: int, applied: int, partial: int, failed: int, taken_off: int, removing: int, rule_today: int}
     */
    public function counts(int $sourceId, CarbonInterface $now): array
    {
        // A block coming off the network's list is no longer in force.
        $inForce = fn (Builder $query): Builder => $query->where('network_state', '!=', TrafficBlockNetworkState::Removing);
        $source = TrafficSource::query()->whereKey($sourceId)->withCount([
            'blocks as sites_count' => fn (Builder $query): Builder => $inForce($query)->where('level', TrafficBlockLevel::Site),
            'blocks as zones_count' => fn (Builder $query): Builder => $inForce($query)->where('level', TrafficBlockLevel::Zone),
            'blocks as by_hand_count' => fn (Builder $query): Builder => $inForce($query)->whereIn('method', [TrafficBlockMethod::Manual, TrafficBlockMethod::Api]),
            'blocks as by_rule_count' => fn (Builder $query): Builder => $inForce($query)->where('method', TrafficBlockMethod::Auto),
            'blocks as in_panel_count' => fn (Builder $query): Builder => $inForce($query)->where('method', TrafficBlockMethod::Panel),
            'blocks as marked_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::ByHand),
            'blocks as pending_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Pending),
            // What RateGuru sent: a block found in the panel is on the lists of its own making.
            'blocks as applied_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Applied)->where('method', '!=', TrafficBlockMethod::Panel),
            'blocks as partial_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Partial),
            'blocks as failed_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Failed),
            'blocks as taken_off_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::TakenOff),
            'blocks as removing_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Removing),
        ])->first();

        return [
            'sites' => (int) ($source->sites_count ?? 0),
            'zones' => (int) ($source->zones_count ?? 0),
            'by_hand' => (int) ($source->by_hand_count ?? 0),
            'by_rule' => (int) ($source->by_rule_count ?? 0),
            'in_panel' => (int) ($source->in_panel_count ?? 0),
            'marked' => (int) ($source->marked_count ?? 0),
            'pending' => (int) ($source->pending_count ?? 0),
            'applied' => (int) ($source->applied_count ?? 0),
            'partial' => (int) ($source->partial_count ?? 0),
            'failed' => (int) ($source->failed_count ?? 0),
            'taken_off' => (int) ($source->taken_off_count ?? 0),
            'removing' => (int) ($source->removing_count ?? 0),
            'rule_today' => $this->blockedByRuleToday($sourceId, $now),
        ];
    }

    /**
     * How many blocks the rule queued for a source today, the UTC day its
     * daily cap counts: read from the rule's own entries in the blocks log,
     * which an unblock leaves in place, so a slot an administrator frees
     * never lets the rule past the cap the same day.
     */
    public function blockedByRuleToday(int $sourceId, CarbonInterface $now): int
    {
        return (int) TrafficBlockEvent::query()
            ->where('source_id', $sourceId)
            ->where('kind', 'queued')
            ->where('actor', TrafficActor::BlockRule)
            ->where('occurred_at', '>=', CarbonImmutable::instance($now)->utc()->startOfDay())
            ->sum('ref_count');
    }

    /**
     * The sites or zones of a source an administrator unblocked in the last
     * 30 days — in RateGuru, or by taking them off every campaign's list in
     * the network's own panel, as a read of the lists saw it — the rule
     * leaves them to the administrator, however their figures read. Each
     * line the read logged names every site or zone it is about.
     *
     * @return list<string>
     */
    public function unblockedLately(int $sourceId, TrafficBlockLevel $level, CarbonInterface $now): array
    {
        return TrafficBlockEvent::query()
            ->where('source_id', $sourceId)
            ->where('level', $level)
            ->where('occurred_at', '>=', CarbonImmutable::instance($now)->subDays(self::UNBLOCKED_KEPT_DAYS))
            ->where(fn (Builder $by) => $by
                ->where(fn (Builder $unblocked) => $unblocked->where('kind', 'unblock')->where('actor', TrafficActor::User)->whereNotNull('ref'))
                // Taken off some lists only, it is still blocked on the others.
                ->orWhere(fn (Builder $inPanel) => $inPanel->where('kind', 'gone')->where('actor', TrafficActor::NetworkPanel)->where('partial', false)))
            ->get(['ref', 'refs'])
            ->flatMap(fn (TrafficBlockEvent $event): array => $event->ref !== null ? [$event->ref] : ($event->refs ?? []))
            ->map(fn (mixed $ref): string => (string) $ref)
            // Compared as they are written: "1" and "01" are two sites.
            ->uniqueStrict()
            ->values()
            ->all();
    }

    /**
     * The campaigns each of a source's blocks sent through the API by
     * RateGuru — by an administrator or the rule — is still to be sent to:
     * every running campaign it is for, all the source's or the one it
     * names, whose list doesn't hold it and says nothing against it — one
     * that came after the block, or whose list was read without it. Never
     * one that refused it or had it taken off in the network's panel; never
     * a block marked by hand or found in the panel, one on its way to or off
     * the lists, one taken off them all, nor one whose unblock failed on a
     * list still holding it — an administrator wants that one off. A block
     * whose listings aren't known yet waits for the read of the lists to
     * know them, but for one sent while the source had no campaign. A
     * campaign that didn't take it only because the network was slow,
     * down or busy (TrafficConnectorFailure::passes()) is sent it again
     * once a read of its own list finds it isn't there
     * (ReadTrafficBlockListsAction): once a read of the lists, never in a
     * loop, and never while that list can't be read — the add may have
     * landed. One query, in the order the blocks and campaigns came.
     *
     * @param  list<TrafficBlockLevel>  $levels  the levels the source's API blocks
     * @return array<int, list<int>> the campaigns, by the block
     */
    public function missingFromCampaigns(int $sourceId, array $levels): array
    {
        if ($levels === []) {
            return [];
        }

        return TrafficBlock::query()
            ->join('traffic_campaigns as targets', fn (JoinClause $join): JoinClause => $join
                ->on('targets.source_id', '=', 'traffic_blocks.source_id')
                ->where('targets.status', '!=', TrafficCampaignStatus::Archived->value)
                ->where(fn (JoinClause $for): JoinClause => $for->whereNull('traffic_blocks.campaign_id')->orWhereColumn('traffic_blocks.campaign_id', 'targets.id')))
            ->leftJoin('traffic_block_listings as standing', fn (JoinClause $join): JoinClause => $join
                ->on('standing.block_id', '=', 'traffic_blocks.id')
                ->on('standing.campaign_id', '=', 'targets.id'))
            ->where('traffic_blocks.source_id', $sourceId)
            ->whereIn('traffic_blocks.level', array_map(fn (TrafficBlockLevel $level): string => $level->value, $levels))
            ->whereIn('traffic_blocks.method', [TrafficBlockMethod::Api->value, TrafficBlockMethod::Auto->value])
            ->whereIn('traffic_blocks.network_state', [TrafficBlockNetworkState::Applied->value, TrafficBlockNetworkState::Partial->value, TrafficBlockNetworkState::Failed->value])
            ->where(fn (Builder $lacking): Builder => $lacking->whereNull('standing.id')
                ->orWhere(fn (Builder $nothing): Builder => $nothing->where('standing.listed', false)->whereNull('standing.error')))
            ->where(fn (Builder $known): Builder => $known->whereHas('listings')->orWhere('traffic_blocks.network_error', BlockStanding::NO_CAMPAIGN))
            ->whereDoesntHave('listings', fn (Builder $stuck): Builder => $stuck->where('listed', true)->whereNotNull('error'))
            ->orderBy('traffic_blocks.id')
            ->orderBy('targets.id')
            ->get(['traffic_blocks.id as missing_block_id', 'targets.id as missing_campaign_id'])
            ->groupBy(fn (TrafficBlock $row): int => (int) $row->getAttribute('missing_block_id'))
            ->map(fn (Collection $rows): array => $rows->map(fn (TrafficBlock $row): int => (int) $row->getAttribute('missing_campaign_id'))->values()->all())
            ->all();
    }

    /** How many accounts the last finished run of chances measured on the whole site: losing is judged from WORTH_KNOWN_AT. */
    public function worthAccounts(): int
    {
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first(['id']);

        return $run === null ? 0 : (int) TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->where('step', 'opened')->value('accounts');
    }
}
