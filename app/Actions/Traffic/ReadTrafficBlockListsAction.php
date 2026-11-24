<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectorFailure;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficBlockListing;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Services\Traffic\Connectors\ConnectorException;
use App\Services\Traffic\Connectors\ConnectorSession;
use App\Services\Traffic\Connectors\ReadsBlockLists;
use App\Support\Traffic\Blocking\BlockStanding;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads — never writes — the block list of each campaign of a source not
 * archived, at every level its network's API blocks, and records what the
 * lists hold (docs/architecture/traffic.md, "Blocking"). A site or zone on
 * a list that RateGuru has no block for was blocked in the network's own
 * panel: it is recorded as blocked there (TrafficBlockMethod::Panel), with
 * the lists that hold it, so it counts as blocked and is never offered to
 * block again; once no list holds it, it was taken off there, and goes.
 * RateGuru's own blocks keep where they stand on each list they are for: a
 * list holding one has it, one taken off a list since is marked taken off
 * there — never put back, which would undo what an administrator did in the
 * panel — so a block off some lists is partly blocked, and off all of them
 * taken off. A campaign that ended without taking a block is no longer one
 * it is for. A block from before where blocks stand was kept starts from
 * what it said: an unblock that failed on the lists holding it, a refusal
 * on those that don't. A block only marked here, or on its way to or off
 * the lists, is left to its own way. The blocks log says what was found,
 * what was taken off every list it was on — a block found in the panel
 * gone, or RateGuru's own taken off — which the block rule then leaves to
 * the owner for 30 days, as it does a site an administrator unblocked, and
 * what was taken off some lists only. A list that couldn't be read is left
 * out — the lists read are recorded all the same — and the first such
 * failure is thrown once they are. It is given the connector as a
 * ReadsBlockLists alone, so it can't write a list.
 */
final class ReadTrafficBlockListsAction
{
    /** The sites or zones a line of the blocks log names, every one of them. */
    private const int REFS_A_LINE = 200;

    /**
     * @throws ConnectorException why the first list that couldn't be read wasn't, once every list read is recorded
     */
    public function handle(int $sourceId, ReadsBlockLists $connector, ConnectorSession $session): void
    {
        // RateGuru's campaign by its ID with the network.
        $campaigns = TrafficCampaign::query()->where('source_id', $sourceId)->where('status', '!=', TrafficCampaignStatus::Archived)->orderBy('id')
            ->get(['id', 'external_id'])->mapWithKeys(fn (TrafficCampaign $campaign): array => [(string) $campaign->external_id => (int) $campaign->id])->all();

        if ($campaigns === []) {
            return;
        }

        $failure = null;

        foreach ($connector->blockLevels() as $level) {
            $result = $connector->blockLists($session, array_map('strval', array_keys($campaigns)), $level);
            $failure ??= $result->failure;
            $read = [];

            foreach ($result->lists as $externalId => $refs) {
                if (isset($campaigns[(string) $externalId])) {
                    $read[$campaigns[(string) $externalId]] = array_fill_keys(array_map(fn (string $ref): string => mb_substr(trim($ref), 0, 120), $refs), true);
                }
            }

            if ($read !== []) {
                $this->record($sourceId, $level, $read);
            }
        }

        // A list that couldn't be read changed nothing, and cost the others nothing: the sync is told whose it was.
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * What the lists read hold, against the source's blocks of the level, all
     * at once on the source locked.
     *
     * @param  array<int, array<array-key, true>>  $read  each campaign's list, by RateGuru's campaign
     */
    private function record(int $sourceId, TrafficBlockLevel $level, array $read): void
    {
        DB::transaction(function () use ($sourceId, $level, $read): void {
            if (TrafficSource::query()->whereKey($sourceId)->lockForUpdate()->first() === null) {
                return;
            }

            $now = now();
            $blocks = TrafficBlock::query()->where('source_id', $sourceId)->where('level', $level)->with('listings')->orderBy('id')->get()
                ->keyBy(fn (TrafficBlock $block): string => (string) $block->ref);
            $found = [];

            foreach ($read as $held) {
                foreach (array_keys($held) as $ref) {
                    if ((string) $ref !== '' && ! $blocks->has((string) $ref)) {
                        $found[(string) $ref] = true;
                    }
                }
            }

            [$listings, $touched, $taken] = $this->standings($blocks, $read, $now);
            $ended = $this->endedWithout($sourceId, $blocks);

            foreach ($this->recordFound($sourceId, $level, array_map('strval', array_keys($found)), $now) as $ref => $id) {
                foreach ($read as $campaignId => $held) {
                    if (isset($held[$ref])) {
                        $listings[] = ['block_id' => $id, 'campaign_id' => $campaignId, 'listed' => true, 'error' => null, 'checked_at' => $now];
                    }
                }
            }

            foreach (array_chunk($listings, 500) as $chunk) {
                TrafficBlockListing::query()->upsert($chunk, ['block_id', 'campaign_id'], ['listed', 'error', 'checked_at']);
            }

            foreach (array_chunk(array_keys($ended), 500) as $chunk) {
                TrafficBlockListing::query()->whereKey($chunk)->delete();
            }

            $off = $this->settle($blocks->whereIn('id', array_values(array_unique([...$touched, ...array_values($ended)]))), $now);
            $this->log($sourceId, $level, array_map('strval', array_keys($found)), 'found', false, 'On the network’s list, blocked in its panel, not by '.site_name(), $now);
            // Off every list it was on: left to the owner as a site an administrator unblocked is (TrafficBlocksQuery::unblockedLately()).
            $this->log($sourceId, $level, $off, 'gone', false, 'Off every campaign’s list: the block rule leaves it alone for 30 days', $now);
            $this->log($sourceId, $level, array_values(array_diff($taken, $off)), 'gone', true, 'Still on other campaigns’ lists', $now);
        });
    }

    /**
     * Where each block that is on the lists, or meant to be, now stands on
     * each list read that it is for — a block found in the panel on those
     * that hold it or held it — written only where it changed; the blocks
     * whose standing changed; and those a list held before and holds no
     * longer.
     *
     * @param  Collection<string, TrafficBlock>  $blocks
     * @param  array<int, array<array-key, true>>  $read
     * @return array{0: list<array<string, mixed>>, 1: list<int>, 2: list<string>}
     */
    private function standings(Collection $blocks, array $read, CarbonInterface $now): array
    {
        $listings = [];
        $touched = [];
        $taken = [];

        foreach ($blocks as $ref => $block) {
            if (in_array($block->network_state, [TrafficBlockNetworkState::ByHand, TrafficBlockNetworkState::Pending, TrafficBlockNetworkState::Removing], true)) {
                continue;
            }

            foreach ($read as $campaignId => $held) {
                if ($block->method !== TrafficBlockMethod::Panel && $block->campaign_id !== null && $block->campaign_id !== $campaignId) {
                    continue;
                }

                $before = $block->listings->firstWhere('campaign_id', $campaignId);
                $listed = isset($held[(string) $ref]);

                // A block found in the panel is kept against the lists that hold it, or held it: it was never for the others.
                if ($block->method === TrafficBlockMethod::Panel && ! $listed && $before === null) {
                    continue;
                }

                // A list holding it has it; one that held it and holds it no longer had it taken off in the panel; one that never held it, still
                // doesn't — and one the network was only slow, down or busy to take it on, read without it, answers that: the add didn't land,
                // so the block is sent there again (QueueMissingTrafficBlocksAction). A block from before where blocks stand was kept starts
                // from what it said.
                [$onList, $error] = match (true) {
                    $listed => [true, $before !== null ? ($before->listed ? $before->error : null) : $this->said($block, TrafficBlockNetworkState::Applied)],
                    $before?->listed === true => [false, TrafficBlockListing::TAKEN_OFF],
                    $before !== null => [false, TrafficConnectorFailure::tryFrom((string) $before->error)?->passes() === true ? null : $before->error],
                    default => [false, $this->said($block, TrafficBlockNetworkState::Failed)],
                };

                if ($before !== null && $before->listed === $onList && $before->error === $error) {
                    continue;
                }

                if ($before?->listed === true && ! $onList) {
                    $taken[(string) $ref] = true;
                }

                $listings[] = ['block_id' => (int) $block->id, 'campaign_id' => $campaignId, 'listed' => $onList, 'error' => $error, 'checked_at' => $now];
                $touched[(int) $block->id] = true;
            }
        }

        return [$listings, array_keys($touched), array_map('strval', array_keys($taken))];
    }

    /**
     * What a block from before where blocks stand was kept said, carried to
     * a list read for the first time: an unblock that failed, for a list
     * holding a block still applied; a campaign's refusal, for a list not
     * holding a block that failed. Nothing for a block whose listings are
     * kept, or one that said something else.
     */
    private function said(TrafficBlock $block, TrafficBlockNetworkState $state): ?string
    {
        return $block->listings->isEmpty() && $block->network_state === $state && TrafficConnectorFailure::tryFrom((string) $block->network_error) !== null
            ? $block->network_error
            : null;
    }

    /**
     * The listings saying a campaign that has ended doesn't hold a block of
     * RateGuru's: a block is no longer for a campaign that ended without
     * taking it, so it isn't partly blocked for ever. A list that holds one
     * is kept — an unblock takes it off there — and so is a block on its way
     * to or off the lists, whose listings its sync writes.
     *
     * @param  Collection<string, TrafficBlock>  $blocks
     * @return array<int, int> each listing's block, by the listing
     */
    private function endedWithout(int $sourceId, Collection $blocks): array
    {
        $ended = array_flip(TrafficCampaign::query()->where('source_id', $sourceId)->where('status', TrafficCampaignStatus::Archived)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $listings = [];

        if ($ended === []) {
            return [];
        }

        foreach ($blocks as $block) {
            if (in_array($block->network_state, [TrafficBlockNetworkState::ByHand, TrafficBlockNetworkState::Pending, TrafficBlockNetworkState::Removing], true)) {
                continue;
            }

            foreach ($block->listings as $listing) {
                if (! $listing->listed && isset($ended[(int) $listing->campaign_id])) {
                    $listings[(int) $listing->id] = (int) $block->id;
                }
            }
        }

        return $listings;
    }

    /**
     * The sites or zones found on a list with no block of RateGuru's, recorded as blocked in the network's panel, for every campaign.
     *
     * @param  list<string>  $refs
     * @return array<string, int> each new block's ID by its site or zone
     */
    private function recordFound(int $sourceId, TrafficBlockLevel $level, array $refs, CarbonInterface $now): array
    {
        $ids = [];
        $batch = (string) Str::ulid();

        foreach (array_chunk($refs, 500) as $chunk) {
            TrafficBlock::query()->insert(array_map(fn (string $ref): array => [
                'source_id' => $sourceId,
                'level' => $level->value,
                'ref' => $ref,
                'campaign_id' => null,
                'method' => TrafficBlockMethod::Panel->value,
                'batch' => $batch,
                'network_state' => TrafficBlockNetworkState::Applied->value,
                'clicks_after' => 0,
                'blocked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));

            foreach (TrafficBlock::query()->where('source_id', $sourceId)->where('level', $level)->whereIn('ref', $chunk)->pluck('id', 'ref') as $ref => $id) {
                $ids[(string) $ref] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Each block whose standing changed, judged again: one found in the
     * panel that no list holds any more goes, and stays blocked there while
     * one does; RateGuru's own takes the state its lists give it. Each is
     * written only over the state it was read in. Those now off every list
     * they were on — gone, or RateGuru's own taken off them all — were taken
     * off in the network's panel.
     *
     * @param  Collection<string, TrafficBlock>  $blocks
     * @return list<string> the sites or zones now off every list
     */
    private function settle(Collection $blocks, CarbonInterface $now): array
    {
        if ($blocks->isEmpty()) {
            return [];
        }

        $standing = TrafficBlockListing::query()->whereIn('block_id', $blocks->pluck('id')->all())->orderBy('campaign_id')->get()->groupBy('block_id');
        $gone = [];
        $off = [];

        foreach ($blocks as $ref => $block) {
            $listings = $standing->get($block->id, collect());
            $panel = $block->method === TrafficBlockMethod::Panel;

            if ($panel && ! $listings->contains('listed', true)) {
                $gone[$block->network_state->value][] = (int) $block->id;
                $off[] = (string) $ref;

                continue;
            }

            $stands = BlockStanding::of($listings, $panel);

            if ($stands['state'] !== $block->network_state || $stands['error'] !== $block->network_error) {
                TrafficBlock::query()->whereKey($block->id)->where('network_state', $block->network_state)
                    ->update(['network_state' => $stands['state'], 'network_error' => $stands['error'], 'updated_at' => $now]);

                if ($stands['state'] === TrafficBlockNetworkState::TakenOff) {
                    $off[] = (string) $ref;
                }
            }
        }

        foreach ($gone as $state => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                TrafficBlock::query()->whereKey($chunk)->where('method', TrafficBlockMethod::Panel)->where('network_state', $state)->delete();
            }
        }

        return $off;
    }

    /**
     * Lines of the blocks log for what the read saw done in the network's
     * panel, 200 sites or zones a line, each line naming every one of them —
     * the block rule reads them back (TrafficBlocksQuery::unblockedLately()).
     *
     * @param  list<string>  $refs
     */
    private function log(int $sourceId, TrafficBlockLevel $level, array $refs, string $kind, bool $partial, string $note, CarbonInterface $now): void
    {
        foreach (array_chunk($refs, self::REFS_A_LINE) as $chunk) {
            TrafficBlockEvent::query()->create([
                'source_id' => $sourceId,
                'kind' => $kind,
                'level' => $level,
                'ref' => count($chunk) === 1 ? $chunk[0] : null,
                'ref_count' => count($chunk),
                'refs' => $chunk,
                'method' => TrafficBlockMethod::Panel,
                'actor' => TrafficActor::NetworkPanel,
                'ok' => true,
                'partial' => $partial,
                'note' => $note,
                'occurred_at' => $now,
            ]);
        }
    }
}
