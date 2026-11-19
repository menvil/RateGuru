<?php

namespace App\Queries\Traffic;

use App\Enums\TrafficStepValueMode;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPriceSet;
use App\Models\TrafficStepChance;
use App\Models\TrafficStepValue;
use App\Support\Traffic\Worth\ChanceTable;
use App\Support\Traffic\Worth\ConfirmationCurve;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\WorthPrices;

/**
 * What a visit's worth is worked out with: the last finished run's chances
 * for its slice and every slice above it, its curve of confirmations, the
 * prices of the visit's own set, and the values the administrator set by
 * hand for its game. Four reads by key; and the prices a slice of the
 * traffic is worth with, its sources' own account prices mixed in.
 */
final class TrafficWorthQuery
{
    public function lastRun(): ?TrafficChanceRun
    {
        return TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
    }

    public function table(?TrafficChanceRun $run, Slice $slice): ChanceTable
    {
        if ($run === null) {
            return new ChanceTable;
        }

        $keys = array_map(fn (Slice $candidate): string => $candidate->key(), $slice->chain());
        $rows = [];

        foreach (TrafficStepChance::query()->where('run_id', $run->id)->whereIn('slice_key', $keys)->get(['slice_key', 'step', 'chance', 'thin']) as $row) {
            $rows[$row->slice_key][$row->step] = ['chance' => (string) $row->chance, 'thin' => (bool) $row->thin];
        }

        return new ChanceTable($rows);
    }

    public function curve(?TrafficChanceRun $run): ConfirmationCurve
    {
        return ConfirmationCurve::fromStored($run?->confirmation_curve);
    }

    /** The prices of a set, with the account price of each paid source that has its own. */
    public function prices(int $priceSetId): WorthPrices
    {
        $set = TrafficPriceSet::query()->find($priceSetId) ?? TrafficPriceSet::current();

        return $set->worth();
    }

    /**
     * The prices a slice is worth with: a paid source's own account price
     * when it has one, the site's for an unpaid channel; and for a slice of
     * every source, the sources' own prices weighed in by how many of the
     * slice's confirmed accounts in the run came from each — the site price
     * before any run or while the slice has no account.
     */
    public function pricesFor(WorthPrices $prices, ?TrafficChanceRun $run, Slice $slice): WorthPrices
    {
        if ($slice->sourceId !== null || $slice->channel !== null || $run === null || $prices->sourceAccountPrices === []) {
            return $prices->forSource($slice->sourceId);
        }

        [$bySource, $all] = $this->ownPriceAccounts($prices, $run, $slice);

        return $prices->mixed($bySource, $all);
    }

    /**
     * Of every source, the slice's confirmed accounts that came from each
     * source with an account price of its own, and all of them, in a run —
     * what an every-source slice mixes those prices in by.
     *
     * @return array{0: array<int, int>, 1: int}
     */
    public function ownPriceAccounts(WorthPrices $prices, ?TrafficChanceRun $run, Slice $slice): array
    {
        if ($run === null || $prices->sourceAccountPrices === []) {
            return [[], 0];
        }

        $keys = [];

        foreach (array_keys($prices->sourceAccountPrices) as $sourceId) {
            $keys[$sourceId] = (new Slice($slice->gameId, $sourceId, null, $slice->device, $slice->group))->key();
        }

        $accounts = TrafficStepChance::query()->where('run_id', $run->id)->where('step', 'opened')
            ->whereIn('slice_key', [$slice->key(), ...array_values($keys)])
            ->pluck('accounts', 'slice_key');

        return [array_map(fn (string $key): int => (int) ($accounts[$key] ?? 0), $keys), (int) ($accounts[$slice->key()] ?? 0)];
    }

    /** @return array<string, array{value: string, mode: TrafficStepValueMode}> by step */
    public function manualValues(?int $gameId): array
    {
        if ($gameId === null) {
            return [];
        }

        return TrafficStepValue::query()->where('game_id', $gameId)->get(['step', 'value', 'mode'])
            ->mapWithKeys(fn (TrafficStepValue $value): array => [$value->step => ['value' => (string) $value->value, 'mode' => $value->mode]])
            ->all();
    }
}
