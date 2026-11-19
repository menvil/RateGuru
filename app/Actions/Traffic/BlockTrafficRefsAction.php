<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficSourceStatus;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficSource;
use App\Models\User;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\EditsBlockLists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Blocks sites or zones of a source: marked in RateGuru by hand — their IDs
 * copied for the network's own black list — or queued for the network's API
 * when the source is connected and its API blocks that level, applied by
 * traffic:sync-networks; or unblocked. Up to 200 at a time, one batch, one
 * line of the log; a site already blocked is left as it is. The block rule
 * blocks nothing for a source no longer set, as it stands locked, to block
 * that level by itself; an administrator blocks whatever it is set to.
 */
final class BlockTrafficRefsAction
{
    use LocksTrafficRecords;

    public const int MOST_REFS = 200;

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @param  list<string>  $refs
     * @return int how many were blocked
     *
     * @throws CannotManageTrafficException
     */
    public function block(?User $admin, TrafficSource $source, TrafficBlockLevel $level, array $refs, ?int $campaignId, bool $viaApi, TrafficBlockMethod $method = TrafficBlockMethod::Manual, ?TrafficBlockVerdict $verdict = null, ?string $reason = null): int
    {
        $refs = array_values(array_unique(array_filter(array_map(fn (string $ref): string => mb_substr(trim($ref), 0, 120), $refs), fn (string $ref): bool => $ref !== '')));

        if ($refs === [] || count($refs) > self::MOST_REFS) {
            throw CannotManageTrafficException::because('Block between 1 and '.self::MOST_REFS.' at a time.');
        }

        return DB::transaction(function () use ($admin, $source, $level, $refs, $campaignId, $viaApi, $method, $verdict, $reason): int {
            $locked = $admin === null ? null : $this->lockAdministrator($admin);
            $current = $this->lockSource((int) $source->id);

            // The block rule blocks only for a source still set to block this level by itself: one paused, switched off or moved to another level since the rule read it is left alone.
            if (($locked === null || $method === TrafficBlockMethod::Auto) && ! $this->blocksByItself($current, $level)) {
                return 0;
            }

            if ($viaApi && ! $this->canBlockViaApi($current, $level)) {
                throw CannotManageTrafficException::because('This source’s API can’t block '.($level === TrafficBlockLevel::Zone ? 'zones' : 'sites').'. Mark them as blocked and add them to the network’s list by hand.');
            }

            $batch = (string) Str::ulid();
            $existing = TrafficBlock::query()->where('source_id', $current->id)->where('level', $level)->whereIn('ref', $refs)->get();
            // A block on its way off the network's list is kept instead: blocked again, it goes back on the list at the next sync.
            TrafficBlock::query()->whereKey($existing->where('network_state', TrafficBlockNetworkState::Removing)->pluck('id')->all())
                ->update(['network_state' => TrafficBlockNetworkState::Pending, 'network_error' => null]);
            $taken = $existing->pluck('ref')->all();
            $new = array_values(array_diff($refs, $taken));

            foreach ($new as $ref) {
                TrafficBlock::query()->create([
                    'source_id' => $current->id,
                    'level' => $level,
                    'ref' => $ref,
                    'campaign_id' => $campaignId,
                    'method' => $viaApi && $method === TrafficBlockMethod::Manual ? TrafficBlockMethod::Api : $method,
                    'verdict' => $verdict,
                    'reason' => $reason === null ? null : mb_substr($reason, 0, 200),
                    'batch' => $batch,
                    'network_state' => $viaApi ? TrafficBlockNetworkState::Pending : TrafficBlockNetworkState::ByHand,
                    'blocked_by_user_id' => $locked?->id,
                    'blocked_at' => now(),
                ]);
            }

            if ($new !== []) {
                TrafficBlockEvent::query()->create([
                    'source_id' => $current->id,
                    'kind' => $viaApi ? 'queued' : 'marked',
                    'level' => $level,
                    'ref' => count($new) === 1 ? $new[0] : null,
                    'ref_count' => count($new),
                    'refs' => array_slice($new, 0, 200),
                    'campaign_id' => $campaignId,
                    'method' => $viaApi && $method === TrafficBlockMethod::Manual ? TrafficBlockMethod::Api : $method,
                    'actor' => $locked === null ? TrafficActor::BlockRule : TrafficActor::User,
                    'actor_user_id' => $locked?->id,
                    'ok' => true,
                    'note' => $viaApi ? 'Waiting to be added to the network’s list' : 'Marked as blocked; add the IDs to the network’s list',
                    'occurred_at' => now(),
                ]);
            }

            return count($new);
        });
    }

    /** @throws CannotManageTrafficException */
    public function unblock(User $admin, TrafficBlock $block): void
    {
        DB::transaction(function () use ($admin, $block): void {
            $locked = $this->lockAdministrator($admin);
            $this->lockSource((int) $block->source_id);
            $record = TrafficBlock::query()->whereKey($block->id)->lockForUpdate()->first();

            // Already on its way off the network's list: nothing more to do.
            if ($record === null || $record->network_state === TrafficBlockNetworkState::Removing) {
                return;
            }

            // A block that may be on the network — applied, failed after some campaigns took it, or being sent there now — is taken off there first; one only marked here simply goes.
            if ($record->network_state !== TrafficBlockNetworkState::ByHand) {
                $record->forceFill(['network_state' => TrafficBlockNetworkState::Removing, 'network_error' => null])->save();
            } else {
                $record->delete();
            }

            TrafficBlockEvent::query()->create([
                'source_id' => $record->source_id,
                'kind' => 'unblock',
                'level' => $record->level,
                'ref' => $record->ref,
                'ref_count' => 1,
                'campaign_id' => $record->campaign_id,
                'method' => $record->method,
                'actor' => TrafficActor::User,
                'actor_user_id' => $locked->id,
                'ok' => true,
                'note' => $record->exists ? 'Waiting to be removed from the network’s list' : 'Unblocked',
                'occurred_at' => now(),
            ]);
        });
    }

    /** Whether the block rule may block this level of the source by itself. */
    private function blocksByItself(TrafficSource $source, TrafficBlockLevel $level): bool
    {
        return $source->status === TrafficSourceStatus::Active && $source->block_auto && $source->block_level === $level
            && $source->connector !== null && $this->registry->verified($source->connector);
    }

    public function canBlockViaApi(TrafficSource $source, TrafficBlockLevel $level): bool
    {
        if ($source->connector === null || ! TrafficApiConnection::query()->where('source_id', $source->id)->where('status', TrafficConnectionStatus::Connected)->exists()) {
            return false;
        }

        $connector = $this->registry->connector($source->connector);

        return $connector instanceof EditsBlockLists && in_array($level, $connector->blockLevels(), true);
    }
}
