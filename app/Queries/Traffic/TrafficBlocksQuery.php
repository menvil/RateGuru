<?php

namespace App\Queries\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficChanceRun;
use App\Models\TrafficSource;
use App\Models\TrafficStepChance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the block rule (traffic:block-losers) and a source's Blocking pane
 * read about blocking, so both count it alike: a source's blocks by level,
 * by who made them and by where they stand with the network, in one query;
 * how many the rule queued in the UTC day its cap counts, from its own
 * entries in the blocks log, so a block taken off again still counts; the
 * sites and zones an administrator unblocked lately, which the rule leaves
 * alone; and how many accounts the worth stands on, which decides whether
 * losing is judged at all.
 */
final class TrafficBlocksQuery
{
    /** Losing is judged only once the worth stands on this many accounts. */
    public const int WORTH_KNOWN_AT = 30;

    /** A site or zone an administrator unblocked is left to them for as long as the rule's figures reach back. */
    public const int UNBLOCKED_KEPT_DAYS = 30;

    /**
     * A source's blocks: those in force by level and by who made them — an
     * administrator, by hand or through the API, or the rule — and every
     * block by where it stands with the network; and how many the rule
     * queued today. Two queries, whatever the number of blocks.
     *
     * @return array{sites: int, zones: int, by_hand: int, by_rule: int, marked: int, pending: int, applied: int, failed: int, removing: int, rule_today: int}
     */
    public function counts(int $sourceId, CarbonInterface $now): array
    {
        // A block coming off the network's list is no longer in force.
        $inForce = fn (Builder $query): Builder => $query->where('network_state', '!=', TrafficBlockNetworkState::Removing);
        $source = TrafficSource::query()->whereKey($sourceId)->withCount([
            'blocks as sites_count' => fn (Builder $query): Builder => $inForce($query)->where('level', TrafficBlockLevel::Site),
            'blocks as zones_count' => fn (Builder $query): Builder => $inForce($query)->where('level', TrafficBlockLevel::Zone),
            'blocks as by_hand_count' => fn (Builder $query): Builder => $inForce($query)->where('method', '!=', TrafficBlockMethod::Auto),
            'blocks as by_rule_count' => fn (Builder $query): Builder => $inForce($query)->where('method', TrafficBlockMethod::Auto),
            'blocks as marked_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::ByHand),
            'blocks as pending_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Pending),
            'blocks as applied_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Applied),
            'blocks as failed_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Failed),
            'blocks as removing_count' => fn (Builder $query): Builder => $query->where('network_state', TrafficBlockNetworkState::Removing),
        ])->first();

        return [
            'sites' => (int) ($source->sites_count ?? 0),
            'zones' => (int) ($source->zones_count ?? 0),
            'by_hand' => (int) ($source->by_hand_count ?? 0),
            'by_rule' => (int) ($source->by_rule_count ?? 0),
            'marked' => (int) ($source->marked_count ?? 0),
            'pending' => (int) ($source->pending_count ?? 0),
            'applied' => (int) ($source->applied_count ?? 0),
            'failed' => (int) ($source->failed_count ?? 0),
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
     * 30 days: the rule leaves them to the administrator, however their
     * figures read.
     *
     * @return list<string>
     */
    public function unblockedLately(int $sourceId, TrafficBlockLevel $level, CarbonInterface $now): array
    {
        return TrafficBlockEvent::query()
            ->where('source_id', $sourceId)
            ->where('kind', 'unblock')
            ->where('actor', TrafficActor::User)
            ->where('level', $level)
            ->whereNotNull('ref')
            ->where('occurred_at', '>=', CarbonImmutable::instance($now)->subDays(self::UNBLOCKED_KEPT_DAYS))
            ->distinct()
            ->pluck('ref')
            ->map(fn (mixed $ref): string => (string) $ref)
            ->values()
            ->all();
    }

    /** How many accounts the last finished run of chances measured on the whole site: losing is judged from WORTH_KNOWN_AT. */
    public function worthAccounts(): int
    {
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first(['id']);

        return $run === null ? 0 : (int) TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->where('step', 'opened')->value('accounts');
    }
}
