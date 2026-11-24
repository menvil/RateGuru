<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficBlockListing;
use App\Models\TrafficSource;
use App\Queries\Traffic\TrafficBlocksQuery;
use Illuminate\Support\Facades\DB;

/**
 * RateGuru's own blocks sent through the API are for every running campaign
 * of their source (docs/architecture/traffic.md, "Blocking"): one a campaign
 * that came after it lacks — or whose list was read without it, nothing
 * refused there — goes back on its way to that campaign alone, marked on its
 * listing (TrafficBlockListing::PENDING), so ApplyTrafficBlocksAction sends
 * it there and leaves where it stands on the other campaigns as it is. A
 * block is never partly blocked merely because a campaign is new; a
 * campaign that refused it keeps its refusal, and one whose list had it
 * taken off in the network's panel is left to whoever did it there. Run by
 * the network sync each turn, after the campaigns and their lists are read,
 * on the source locked, then its blocks, as every writer of blocks takes
 * them. One line of the blocks log a level.
 */
final class QueueMissingTrafficBlocksAction
{
    /** Where a block stands that is sent to the campaigns it is missing from: not on its way to or off the lists, nor taken off them all. */
    private const array QUEUED_FROM = [TrafficBlockNetworkState::Applied, TrafficBlockNetworkState::Partial, TrafficBlockNetworkState::Failed];

    public function __construct(private readonly TrafficBlocksQuery $blocks) {}

    /**
     * @param  list<TrafficBlockLevel>  $levels  the levels the source's API blocks
     * @return int how many blocks are on their way to a campaign
     */
    public function handle(int $sourceId, array $levels): int
    {
        // Most turns have nothing to send: read first, and lock nothing for nothing.
        if ($this->blocks->missingFromCampaigns($sourceId, $levels) === []) {
            return 0;
        }

        return DB::transaction(function () use ($sourceId, $levels): int {
            if (TrafficSource::query()->whereKey($sourceId)->lockForUpdate()->first() === null) {
                return 0;
            }

            // Read again on the source locked: an unblock, a push or a batch written meanwhile is taken into account.
            $missing = $this->blocks->missingFromCampaigns($sourceId, $levels);
            $blocks = TrafficBlock::query()->whereKey(array_keys($missing))->whereIn('network_state', self::QUEUED_FROM)
                ->orderBy('id')->lockForUpdate()->get(['id', 'level', 'ref']);

            if ($blocks->isEmpty()) {
                return 0;
            }

            $now = now();
            $marks = [];

            foreach ($blocks as $block) {
                foreach ($missing[(int) $block->id] as $campaignId) {
                    $marks[] = ['block_id' => (int) $block->id, 'campaign_id' => $campaignId, 'listed' => false, 'error' => TrafficBlockListing::PENDING, 'checked_at' => $now];
                }
            }

            foreach (array_chunk($marks, 500) as $chunk) {
                TrafficBlockListing::query()->upsert($chunk, ['block_id', 'campaign_id'], ['listed', 'error', 'checked_at']);
            }

            TrafficBlock::query()->whereKey($blocks->pluck('id')->all())
                ->increment('request_version', 1, ['network_state' => TrafficBlockNetworkState::Pending, 'network_error' => null, 'updated_at' => $now]);

            foreach ($blocks->groupBy(fn (TrafficBlock $block): string => $block->level->value) as $level => $group) {
                $refs = $group->pluck('ref')->map(fn (mixed $ref): string => (string) $ref)->values()->all();

                TrafficBlockEvent::query()->create([
                    'source_id' => $sourceId,
                    'kind' => 'queued',
                    'level' => $level,
                    'ref' => count($refs) === 1 ? $refs[0] : null,
                    'ref_count' => count($refs),
                    'refs' => array_slice($refs, 0, 200),
                    'method' => TrafficBlockMethod::Api,
                    'actor' => TrafficActor::System,
                    'ok' => true,
                    'note' => 'Waiting to be added to the campaigns whose lists don’t have it yet',
                    'occurred_at' => $now,
                ]);
            }

            return $blocks->count();
        });
    }
}
