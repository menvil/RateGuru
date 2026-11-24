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
use App\Models\TrafficBlockListing;
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
 * traffic:sync-networks; sends blocks to the network again; or unblocks.
 * Up to 200 at a time, one batch, one line of the log. A site already
 * blocked is left as it is, but for one on its way off the list, which goes
 * back on it, and one an administrator blocks again through the API that
 * isn't on every list it is for — refused, on some only, taken off in the
 * network's panel — which is sent again. The block rule blocks nothing for
 * a source no longer set, as it stands locked, to block that level by
 * itself, and never sends a block again; an administrator blocks whatever
 * it is set to.
 */
final class BlockTrafficRefsAction
{
    use LocksTrafficRecords;

    public const int MOST_REFS = 200;

    /** Where a block stands that blocking it again through the API sends it again from: not on every list it is for. */
    private const array SENT_AGAIN = [TrafficBlockNetworkState::Failed, TrafficBlockNetworkState::Partial, TrafficBlockNetworkState::TakenOff];

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
            // A block on its way off the network's list is kept instead: blocked again, it goes back on the list at the next sync. So does
            // one an administrator blocks again through the API that isn't on every list it is for.
            $again = $existing->filter(fn (TrafficBlock $block): bool => $block->network_state === TrafficBlockNetworkState::Removing
                || ($locked !== null && $viaApi && $method !== TrafficBlockMethod::Auto && in_array($block->network_state, self::SENT_AGAIN, true)));
            TrafficBlock::query()->whereKey($again->pluck('id')->all())
                ->increment('request_version', 1, ['network_state' => TrafficBlockNetworkState::Pending, 'network_error' => null]);
            $this->forgetMarks($again->modelKeys());
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

            $sent = $again->where('network_state', '!=', TrafficBlockNetworkState::Removing)->pluck('ref')->map(fn (mixed $ref): string => (string) $ref)->values()->all();

            if ($sent !== [] && $locked !== null) {
                $this->logSentAgain($current, $level, $sent, $locked);
            }

            return count($new);
        });
    }

    /**
     * Sends blocks to the network again, to every campaign each is for: one
     * on some campaigns' lists only, refused, taken off in the network's
     * panel, or whose clicks still come; one marked by hand, or found in the
     * network's panel, becomes RateGuru's own, sent through the API. One
     * already on its way there is left as it is.
     *
     * @param  list<string>  $refs
     * @return int how many go again
     *
     * @throws CannotManageTrafficException
     */
    public function push(User $admin, TrafficSource $source, TrafficBlockLevel $level, array $refs): int
    {
        if ($refs === [] || count($refs) > self::MOST_REFS) {
            throw CannotManageTrafficException::because('Push between 1 and '.self::MOST_REFS.' at a time.');
        }

        return DB::transaction(function () use ($admin, $source, $level, $refs): int {
            $locked = $this->lockAdministrator($admin);
            $current = $this->lockSource((int) $source->id);

            if (! $this->canBlockViaApi($current, $level)) {
                throw CannotManageTrafficException::because('This source’s API can’t block '.($level === TrafficBlockLevel::Zone ? 'zones' : 'sites').': add them to the network’s list by hand.');
            }

            $blocks = TrafficBlock::query()->where('source_id', $current->id)->where('level', $level)->whereIn('ref', $refs)
                ->where('network_state', '!=', TrafficBlockNetworkState::Pending)->lockForUpdate()->get();
            $this->forgetMarks($blocks->modelKeys());

            foreach ($blocks as $block) {
                $block->forceFill([
                    'network_state' => TrafficBlockNetworkState::Pending,
                    'network_error' => null,
                    'request_version' => $block->request_version + 1,
                    'method' => in_array($block->method, [TrafficBlockMethod::Manual, TrafficBlockMethod::Panel], true) ? TrafficBlockMethod::Api : $block->method,
                ])->save();
            }

            if ($blocks->isNotEmpty()) {
                $this->logSentAgain($current, $level, $blocks->pluck('ref')->map(fn (mixed $ref): string => (string) $ref)->values()->all(), $locked);
            }

            return $blocks->count();
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

            $listings = $record->listings()->get();

            // A block that may be on the network — on its campaigns' lists, on some of them, found blocked in the network's panel, or being sent
            // there now, or one that failed where where it stands isn't known, or only because the network was slow, down or busy — is taken
            // off there first, from the lists that may hold it; one only marked here, or that no list holds, simply goes.
            if ($record->network_state !== TrafficBlockNetworkState::ByHand && ($listings->isEmpty() || $listings->contains(fn (TrafficBlockListing $listing): bool => $listing->mayBeListed()) || $record->network_state === TrafficBlockNetworkState::Pending)) {
                $record->forceFill(['network_state' => TrafficBlockNetworkState::Removing, 'network_error' => null, 'request_version' => $record->request_version + 1])->save();
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

    /**
     * A block sent again by an administrator goes to every campaign it is
     * for: the marks the sync left on it for the campaigns it was missing
     * from (TrafficBlockListing::PENDING) — kept by an unblock before they
     * went — go, or it would go to those alone. Only an unblock leaves a
     * block not waiting with marks, so the sync's own are never touched.
     *
     * @param  list<int>  $blockIds
     */
    private function forgetMarks(array $blockIds): void
    {
        if ($blockIds !== []) {
            TrafficBlockListing::query()->whereIn('block_id', $blockIds)->where('error', TrafficBlockListing::PENDING)->delete();
        }
    }

    /** @param  list<string>  $refs */
    private function logSentAgain(TrafficSource $source, TrafficBlockLevel $level, array $refs, User $admin): void
    {
        TrafficBlockEvent::query()->create([
            'source_id' => $source->id,
            'kind' => 'queued',
            'level' => $level,
            'ref' => count($refs) === 1 ? $refs[0] : null,
            'ref_count' => count($refs),
            'refs' => array_slice($refs, 0, 200),
            'method' => TrafficBlockMethod::Api,
            'actor' => TrafficActor::User,
            'actor_user_id' => $admin->id,
            'ok' => true,
            'note' => 'Waiting to go to the network’s list again',
            'occurred_at' => now(),
        ]);
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
