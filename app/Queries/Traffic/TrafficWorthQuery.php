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
 * hand for its game. Four reads by key.
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

    public function prices(int $priceSetId): WorthPrices
    {
        $set = TrafficPriceSet::query()->find($priceSetId) ?? TrafficPriceSet::current();

        return $set->worth();
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
