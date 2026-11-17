<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Support\Traffic\Spend\SpendAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shares one day of a source's spend out over its visits (SpendAllocator):
 * the day is counted from one origin — a typed total, else a file, else the
 * network's API, else the fixed price — and that origin's figures are
 * shared out over the visits they cover: every visit of the day, or for the
 * API the visits whose spend was an estimate waiting for it. A visit is
 * written only when its spend moved, in batches by key; an interrupted run
 * is finished by the next. A fixed price stays an estimate while its day
 * is not over.
 */
final class AllocateSpendDayAction
{
    private const int BATCH = 500;

    private const array ORDER = [TrafficSpendOrigin::Manual, TrafficSpendOrigin::Csv, TrafficSpendOrigin::Api, TrafficSpendOrigin::Fixed];

    public function handle(TrafficSource $source, string $day, CarbonImmutable $now): void
    {
        $facts = TrafficSpendDay::query()->where('source_id', $source->id)->where('network_day', $day)->get();
        $origin = collect(self::ORDER)->first(fn (TrafficSpendOrigin $candidate): bool => $facts->contains('origin', $candidate));

        if ($origin === null) {
            return;
        }

        $counted = $facts->where('origin', $origin)->values();
        $start = CarbonImmutable::parse($day, $source->reporting_timezone)->startOfDay();
        $visits = TrafficVisit::query()
            ->where('source_id', $source->id)
            ->where('arrived_at', '>=', $start->utc())
            ->where('arrived_at', '<', $start->addDay()->utc())
            ->where('network_day', $day)
            ->when($origin === TrafficSpendOrigin::Api, fn ($query) => $query->where(fn ($covered) => $covered->where('spend_estimated', true)->orWhere('spend_basis', TrafficSpendBasis::Api)))
            ->orderBy('id')
            ->get(['id', 'campaign_id', 'site_ref', 'zone_ref', 'cost', 'spend', 'spend_basis', 'spend_estimated']);

        $plan = SpendAllocator::plan(
            $counted->map(fn (TrafficSpendDay $fact): array => [
                'id' => (int) $fact->id, 'campaign_id' => $fact->campaign_id, 'site_ref' => $fact->site_ref, 'zone_ref' => $fact->zone_ref, 'amount' => (string) $fact->amount,
            ])->all(),
            $visits->map(fn (TrafficVisit $visit): array => [
                'id' => (int) $visit->id, 'campaign_id' => $visit->campaign_id, 'site_ref' => $visit->site_ref, 'zone_ref' => $visit->zone_ref, 'cost' => $visit->cost === null ? null : (string) $visit->cost,
            ])->all(),
        );
        $basis = TrafficSpendBasis::from($origin->value);
        $estimated = $origin === TrafficSpendOrigin::Fixed && $start->addDay()->greaterThan($now);

        foreach ($visits->chunk(self::BATCH) as $batch) {
            DB::transaction(function () use ($batch, $plan, $basis, $estimated): void {
                foreach ($batch as $visit) {
                    $spend = $plan['visits'][(int) $visit->id];

                    if ((string) $visit->spend !== $spend || $visit->spend_basis !== $basis || (bool) $visit->spend_estimated !== $estimated) {
                        TrafficVisit::query()->whereKey($visit->id)->update(['spend' => $spend, 'spend_basis' => $basis, 'spend_estimated' => $estimated]);
                    }
                }
            });
        }

        DB::transaction(function () use ($facts, $counted, $plan, $now): void {
            foreach ($facts as $fact) {
                $isCounted = $counted->contains('id', $fact->id);
                TrafficSpendDay::query()->whereKey($fact->id)->update([
                    'counted' => $isCounted,
                    'allocated_amount' => $isCounted ? $plan['facts'][(int) $fact->id]['allocated'] : 0,
                    'matched_visits' => $isCounted ? $plan['facts'][(int) $fact->id]['matched'] : 0,
                    'allocated_at' => $now,
                ]);
            }
        });
    }
}
