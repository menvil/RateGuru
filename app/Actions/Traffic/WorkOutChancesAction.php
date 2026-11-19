<?php

namespace App\Actions\Traffic;

use App\Models\TrafficChanceRun;
use App\Models\TrafficPriceSet;
use App\Models\TrafficStepChance;
use App\Queries\Traffic\TrafficChanceSampleQuery;
use App\Support\Traffic\Worth\ChanceCalculator;
use App\Support\Traffic\Worth\ConfirmationCurve;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Works out the chances anew from the matured visits (ChanceCalculator) and
 * keeps them as a run with its rows, written together: a step's worth reads
 * the last finished run, so it never sees half of one. The country groups of
 * the prices in force decide each visit's group. With no matured visits yet
 * the run is empty, and every chance stays 0. Only the last runs are kept.
 */
final class WorkOutChancesAction
{
    public function __construct(private readonly TrafficChanceSampleQuery $sample) {}

    public function handle(string $reason, CarbonImmutable $now): TrafficChanceRun
    {
        $prices = TrafficPriceSet::current();
        $run = TrafficChanceRun::query()->create([
            'status' => 'running',
            'reason' => mb_substr($reason, 0, 16),
            'price_set_id' => $prices->id,
            'matured_before' => $now,
            'started_at' => now(),
        ]);

        try {
            $cells = $this->sample->rows($now);
            $rows = ChanceCalculator::calculate($cells, $prices->country_groups);
            $curve = $this->curve($cells);

            DB::transaction(function () use ($run, $rows, $cells, $curve): void {
                foreach (array_chunk($rows, 500) as $chunk) {
                    TrafficStepChance::query()->insert(array_map(fn (array $row): array => [
                        'run_id' => $run->id,
                        'slice_key' => $row['slice']->key(),
                        'game_id' => $row['slice']->gameId,
                        'source_id' => $row['slice']->sourceId,
                        'channel' => $row['slice']->channel,
                        'device' => $row['slice']->device,
                        'country_group' => $row['slice']->group,
                        'step' => $row['step'],
                        'visitors' => $row['visitors'],
                        'accounts' => $row['accounts'],
                        'slice_accounts' => $row['slice_accounts'],
                        'chance' => number_format($row['chance'], 8, '.', ''),
                        'thin' => $row['thin'],
                        'stands_on' => $row['stands_on'],
                    ], $chunk));
                }

                $run->forceFill([
                    'status' => 'finished',
                    'visits_scanned' => array_sum(array_map(fn (array $cell): int => (int) $cell['visits'], $cells)),
                    'confirmation_curve' => $curve->accounts > 0 ? $curve->toStored() : null,
                    'finished_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 300), 'finished_at' => now()])->save();

            throw $exception;
        }

        $this->keepLatest();

        return $run;
    }

    /** @param  list<array<string, mixed>>  $cells */
    private function curve(array $cells): ConfirmationCurve
    {
        $counts = [];

        foreach (ConfirmationCurve::GRID as $index => $seconds) {
            $counts[] = array_sum(array_map(fn (array $cell): int => (int) ($cell['curve_'.$index] ?? 0), $cells));
        }

        return new ConfirmationCurve($counts, array_sum(array_map(fn (array $cell): int => (int) $cell['accounts'], $cells)));
    }

    private function keepLatest(): void
    {
        $keep = (int) config('traffic.chance_runs_kept', 30);
        $oldest = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->skip($keep)->take(1)->value('id');

        if ($oldest !== null) {
            TrafficChanceRun::query()->where('id', '<=', $oldest)->delete();
        }
    }
}
