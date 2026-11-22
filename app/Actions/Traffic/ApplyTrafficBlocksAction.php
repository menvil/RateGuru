<?php

namespace App\Actions\Traffic;

use App\Actions\Traffic\Concerns\LocksTrafficRecords;
use App\Enums\TrafficActor;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnectorFailure;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficBlockListing;
use App\Models\TrafficCampaign;
use App\Services\Traffic\Connectors\BlockDeltaResult;
use App\Services\Traffic\Connectors\ConnectorException;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\ConnectorSession;
use App\Services\Traffic\Connectors\EditsBlockLists;
use App\Support\Traffic\Blocking\BlockStanding;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies the blocks waiting for a source's network — to the campaign each
 * names, or every campaign of the source not archived, or only those a
 * block was missing from and is marked for (TrafficBlockListing::PENDING),
 * its standing on the others kept — one change per campaign with the sites
 * or zones it is to get, and takes the ones being unblocked
 * off the campaigns whose lists hold them (docs/architecture/traffic.md,
 * "Blocking"). Each site or zone is judged on its own by the list each
 * campaign reads back, and where it stands on each is kept
 * (TrafficBlockListing): on every list it is applied, on some partial, on
 * none failed with the reason (`network_error` keeps the
 * TrafficConnectorFailure, or `no_campaign`); one unblocked goes once no
 * list holds it, and stays with the reason where one still does — one found
 * in the network's panel stays blocked there (BlockStanding). A call
 * that failed changed nothing: a list that held a site holds it still. One
 * line of the log per batch, with the HTTP code and how many campaigns took
 * it all, saying whether it went all the way or part of it. Safe to repeat:
 * each change reads the list first, and never touches a site or zone it
 * isn't adding or taking off.
 */
final class ApplyTrafficBlocksAction
{
    use LocksTrafficRecords;

    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function handle(TrafficApiConnection $connection): void
    {
        $blocks = TrafficBlock::query()->where('source_id', $connection->source_id)
            ->whereIn('network_state', [TrafficBlockNetworkState::Pending, TrafficBlockNetworkState::Removing])
            ->with('listings')->orderBy('id')->get();

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

        $campaigns = TrafficCampaign::query()->where('source_id', $connection->source_id)->orderBy('id')->get(['id', 'external_id', 'status']);
        // Every campaign's ID with the network, archived ones too — a list that holds a block being unblocked is one to take it off — and those a block is sent to.
        $external = $campaigns->mapWithKeys(fn (TrafficCampaign $campaign): array => [(int) $campaign->id => (string) $campaign->external_id])->all();
        $running = $campaigns->where('status', '!=', TrafficCampaignStatus::Archived)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        foreach ($blocks->groupBy(fn (TrafficBlock $block): string => $block->level->value.'|'.($block->campaign_id ?? '*')) as $group) {
            $this->apply($connection, $connector, $session, $group->values(), $external, $running);

            if ($session->changed()) {
                $connection->forceFill(['credentials' => $session->credentials()])->save();
            }
        }
    }

    /**
     * One batch: the blocks of a level for one campaign, or for every one.
     *
     * @param  Collection<int, TrafficBlock>  $group
     * @param  array<int, string>  $external  each campaign's ID with the network
     * @param  list<int>  $running  the campaigns not archived
     */
    private function apply(TrafficApiConnection $connection, EditsBlockLists $connector, ConnectorSession $session, Collection $group, array $external, array $running): void
    {
        $first = $group->first();
        $targets = $first->campaign_id === null ? $running : array_values(array_intersect($running, [(int) $first->campaign_id]));
        $pending = $group->where('network_state', TrafficBlockNetworkState::Pending)->values();
        $removing = $group->where('network_state', TrafficBlockNetworkState::Removing)->values();
        // A block waiting goes to the running campaigns marked for it alone — those it was missing from (QueueMissingTrafficBlocksAction) —
        // and keeps where it stands on the others; any other, to every running campaign it is for.
        $marked = $pending->mapWithKeys(fn (TrafficBlock $block): array => [(int) $block->id => $block->listings->where('error', TrafficBlockListing::PENDING)->pluck('campaign_id')->map(fn (mixed $id): int => (int) $id)->values()->all()])->all();
        $to = $pending->mapWithKeys(fn (TrafficBlock $block): array => [(int) $block->id => $marked[(int) $block->id] === [] ? $targets : array_values(array_intersect($targets, $marked[(int) $block->id]))])->all();
        // A block being unblocked comes off the campaigns whose lists hold it; while where it stands isn't known, off every running one it is
        // for — or the one it names, ended or not, as it was put there.
        $from = $removing->mapWithKeys(fn (TrafficBlock $block): array => [(int) $block->id => array_values(array_filter(
            match (true) {
                $block->listings->isNotEmpty() => $block->listings->where('listed', true)->pluck('campaign_id')->map(fn (mixed $id): int => (int) $id)->all(),
                $block->campaign_id !== null => [(int) $block->campaign_id],
                default => $targets,
            },
            fn (int $id): bool => isset($external[$id]),
        ))])->all();
        $calls = array_values(array_unique([...array_merge([], ...array_values($to)), ...array_merge([], ...array_values($from))]));
        sort($calls);

        /** @var array<int, BlockDeltaResult|ConnectorException> $results */
        $results = [];

        foreach ($calls as $campaignId) {
            $add = $pending->filter(fn (TrafficBlock $block): bool => in_array($campaignId, $to[(int) $block->id], true))->map(fn (TrafficBlock $block): string => (string) $block->ref)->values()->all();
            $remove = $removing->filter(fn (TrafficBlock $block): bool => in_array($campaignId, $from[(int) $block->id], true))->map(fn (TrafficBlock $block): string => (string) $block->ref)->values()->all();

            try {
                $results[$campaignId] = $connector->applyBlockDelta($session, $external[$campaignId], $first->level, $add, $remove);
            } catch (ConnectorException $exception) {
                $results[$campaignId] = $exception;
            }
        }

        $listings = [];
        $now = now();
        $row = fn (TrafficBlock $block, int $campaignId, bool $listed, ?string $error): array => ['block_id' => (int) $block->id, 'campaign_id' => $campaignId, 'listed' => $listed, 'error' => $error, 'checked_at' => $now];

        foreach ($pending as $block) {
            foreach ($to[(int) $block->id] as $campaignId) {
                $result = $results[$campaignId];
                $listings[] = match (true) {
                    $result instanceof BlockDeltaResult => in_array((string) $block->ref, $result->listed, true)
                        ? $row($block, $campaignId, true, null)
                        : $row($block, $campaignId, false, TrafficConnectorFailure::Rejected->value),
                    $block->listings->firstWhere('campaign_id', $campaignId)?->listed === true => $row($block, $campaignId, true, null),
                    default => $row($block, $campaignId, false, $result->failure->value),
                };
            }
        }

        foreach ($removing as $block) {
            foreach ($from[(int) $block->id] as $campaignId) {
                $result = $results[$campaignId];
                $listings[] = match (true) {
                    $result instanceof ConnectorException => $row($block, $campaignId, true, $result->failure->value),
                    in_array((string) $block->ref, $result->listed, true) => $row($block, $campaignId, true, TrafficConnectorFailure::Rejected->value),
                    default => $row($block, $campaignId, false, null),
                };
            }
        }

        $last = $results === [] ? null : $results[array_key_last($results)];
        $status = $last?->httpStatus;
        $done = count(array_filter($results, fn (BlockDeltaResult|ConnectorException $result): bool => $result instanceof BlockDeltaResult && $result->applied));

        // Where the blocks stand, their new states and the batch's line of the log, together: a log line that can't be written leaves the blocks waiting, to be sent again.
        DB::transaction(function () use ($connection, $group, $first, $pending, $removing, $targets, $marked, $to, $listings, $status, $done, $calls): void {
            // The source, then its blocks, in the order every writer of blocks locks them (LocksTrafficRecords): an unblock, a push or a read
            // of the lists waits for this batch, or this batch for it, and none of them can delete a block under the listings written here.
            $this->lockSource((int) $connection->source_id);
            // What the lists hold is kept for every block still there, whatever became of it meanwhile: an unblock takes it off the lists these say hold it.
            $present = array_flip(TrafficBlock::query()->whereKey($group->pluck('id')->all())->orderBy('id')->lockForUpdate()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $kept = array_values(array_filter($listings, fn (array $listing): bool => isset($present[$listing['block_id']])));

            foreach (array_chunk($kept, 500) as $chunk) {
                TrafficBlockListing::query()->upsert($chunk, ['block_id', 'campaign_id'], ['listed', 'error', 'checked_at']);
            }

            // A block sent to every campaign is no longer for one it wasn't sent to that doesn't hold it; one sent to those it was missing from
            // keeps where it stands on the others. Nothing is on its way anywhere once the batch is written: a campaign marked that ended
            // meanwhile, or a block unblocked since it was marked, is no longer waited for.
            $everywhere = $pending->filter(fn (TrafficBlock $block): bool => $marked[(int) $block->id] === [])->pluck('id')->all();

            if ($everywhere !== []) {
                TrafficBlockListing::query()->whereIn('block_id', $everywhere)->whereNotIn('campaign_id', $targets)->where('listed', false)->delete();
            }

            TrafficBlockListing::query()->whereIn('block_id', $group->pluck('id')->all())->where('error', TrafficBlockListing::PENDING)->delete();

            $standing = TrafficBlockListing::query()->whereIn('block_id', $group->pluck('id')->all())->orderBy('campaign_id')->get()->groupBy('block_id');
            $outcomes = [];
            $updates = [];

            foreach ($pending as $block) {
                $listings = $standing->get($block->id, collect());
                $stands = BlockStanding::of($listings);
                // The batch is judged by what it sent: a block sent only where it was missing went all the way once those campaigns took it.
                $sent = $marked[(int) $block->id] === [] || $to[(int) $block->id] === [] ? $stands : BlockStanding::of($listings->whereIn('campaign_id', $to[(int) $block->id]));
                $outcomes[] = match ($sent['state']) {
                    TrafficBlockNetworkState::Applied => 'blocked',
                    TrafficBlockNetworkState::Partial => 'partly blocked',
                    default => 'not blocked',
                };
                $updates[TrafficBlockNetworkState::Pending->value][$stands['state']->value.'|'.$stands['error']][] = $block->id;
            }

            $gone = [];

            foreach ($removing as $block) {
                $held = $standing->get($block->id, collect())->where('listed', true);
                $off = $standing->get($block->id, collect())->where('listed', false)->whereIn('campaign_id', $calls)->isNotEmpty();
                $outcomes[] = match (true) {
                    $held->isEmpty() => 'taken off',
                    $off => 'still on some lists',
                    default => 'still on the lists',
                };

                if ($held->isEmpty()) {
                    $gone[] = $block->id;

                    continue;
                }

                $stands = BlockStanding::of($standing->get($block->id, collect()), $block->method === TrafficBlockMethod::Panel);
                $updates[TrafficBlockNetworkState::Removing->value][$stands['state']->value.'|'.$stands['error']][] = $block->id;
            }

            // Each result is written only over the state it was read in: a block unblocked, or blocked again, while the network was being called keeps its new state for the next sync.
            if ($gone !== []) {
                TrafficBlock::query()->whereKey($gone)->where('network_state', TrafficBlockNetworkState::Removing)->delete();
            }

            foreach ($updates as $was => $byStanding) {
                foreach ($byStanding as $key => $ids) {
                    [$state, $error] = explode('|', $key, 2);
                    TrafficBlock::query()->whereKey($ids)->where('network_state', $was)->update(['network_state' => $state, 'network_error' => $error === '' ? null : $error]);
                }
            }

            // Blocks waiting only for campaigns archived before they went sent nothing: they are back where they stood, and no line of the log
            // says they were applied.
            if ($calls === [] && $removing->isEmpty() && $pending->every(fn (TrafficBlock $block): bool => $marked[(int) $block->id] !== [])) {
                return;
            }

            $whole = array_filter($outcomes, fn (string $outcome): bool => in_array($outcome, ['blocked', 'taken off'], true));
            $ok = count($whole) === count($outcomes);
            $mixed = array_count_values($outcomes);

            TrafficBlockEvent::query()->create([
                'source_id' => $connection->source_id,
                'kind' => $removing->isNotEmpty() && $pending->isEmpty() ? 'removed' : 'applied',
                'level' => $first->level,
                'ref' => $group->count() === 1 ? $first->ref : null,
                'ref_count' => $group->count(),
                'refs' => $group->pluck('ref')->take(200)->values()->all(),
                'campaign_id' => $first->campaign_id,
                'method' => TrafficBlockMethod::Api,
                'actor' => TrafficActor::NetworkApi,
                'ok' => $ok,
                'partial' => ! $ok && ($whole !== [] || in_array('partly blocked', $outcomes, true) || in_array('still on some lists', $outcomes, true)),
                'http_status' => $status,
                'note' => mb_substr($done.' of '.count($calls).' campaigns'.(count($mixed) > 1 ? ' · '.collect($mixed)->map(fn (int $count, string $outcome): string => $count.' '.$outcome)->implode(', ') : ''), 0, 300),
                'occurred_at' => now(),
            ]);
        });
    }
}
