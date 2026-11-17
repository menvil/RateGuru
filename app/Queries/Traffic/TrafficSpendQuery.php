<?php

namespace App\Queries\Traffic;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Support\Traffic\Money\TrafficMoney;

/**
 * Spend as the Spend tab reconciles it (docs/architecture/traffic.md,
 * "Spend"): what was spent with no visit to share it over, by source and
 * campaign, and each day of a source — its clicks, what their links said
 * they cost, what was counted on the visits, and the day's figures by
 * origin. Sums with every value bound.
 */
final class TrafficSpendQuery implements RawSqlPersistenceBoundary
{
    /** @return list<array{source_id: int, campaign_id: int|null, amount: string}> */
    public function unallocated(string $fromDay, string $toDay, ?int $sourceId = null): array
    {
        return TrafficSpendDay::query()
            ->where('counted', true)
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->groupBy('source_id', 'campaign_id')
            ->select('source_id', 'campaign_id')
            ->selectRaw('SUM(amount - allocated_amount) AS amount', [])
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['source_id' => (int) $row->source_id, 'campaign_id' => $row->campaign_id === null ? null : (int) $row->campaign_id, 'amount' => TrafficMoney::stored($row->amount)])
            ->filter(fn (array $row): bool => TrafficMoney::of($row['amount'])->isPositive())
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{clicks: int, costs: string, counted: string, estimated: string, figures: array<string, string>, counted_origin: string|null, allocated: bool}> by day
     */
    public function days(int $sourceId, string $fromDay, string $toDay): array
    {
        $days = [];

        foreach (TrafficVisit::query()
            ->where('source_id', $sourceId)
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->groupBy('network_day')
            ->select('network_day')
            ->selectRaw('COUNT(*) AS clicks, SUM(cost) AS costs, SUM(spend) AS counted, SUM(CASE WHEN spend_estimated THEN spend ELSE 0 END) AS estimated', [])
            ->toBase()
            ->get() as $row) {
            $days[(string) $row->network_day] = [
                'clicks' => (int) $row->clicks,
                'costs' => TrafficMoney::stored($row->costs),
                'counted' => TrafficMoney::stored($row->counted),
                'estimated' => TrafficMoney::stored($row->estimated),
                'figures' => [],
                'counted_origin' => null,
                'allocated' => true,
            ];
        }

        foreach (TrafficSpendDay::query()
            ->where('source_id', $sourceId)
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->groupBy('network_day', 'origin')
            ->select('network_day', 'origin')
            ->selectRaw('SUM(amount) AS amount, MAX(CASE WHEN counted THEN 1 ELSE 0 END) AS counted, SUM(CASE WHEN allocated_at IS NULL THEN 1 ELSE 0 END) AS waiting', [])
            ->toBase()
            ->get() as $row) {
            $day = (string) $row->network_day;
            $days[$day] ??= ['clicks' => 0, 'costs' => TrafficMoney::stored(0), 'counted' => TrafficMoney::stored(0), 'estimated' => TrafficMoney::stored(0), 'figures' => [], 'counted_origin' => null, 'allocated' => true];
            $days[$day]['figures'][(string) $row->origin] = TrafficMoney::stored($row->amount);
            $days[$day]['counted_origin'] = (int) $row->counted === 1 ? (string) $row->origin : $days[$day]['counted_origin'];
            $days[$day]['allocated'] = $days[$day]['allocated'] && (int) $row->waiting === 0;
        }

        krsort($days);

        return $days;
    }
}
