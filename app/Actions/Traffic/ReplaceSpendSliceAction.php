<?php

namespace App\Actions\Traffic;

use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Support\Traffic\Money\TrafficMoney;
use Carbon\CarbonImmutable;

/**
 * Puts a source's figures for one day from one origin — the API, a file, a
 * typed total, a fixed price — in place of the ones it had. The same figures
 * again change nothing; different ones replace the slice whole and send the
 * day back to be shared out over its visits again — or, when nothing is left
 * for the day, put its clicks back to their own prices at once.
 */
final class ReplaceSpendSliceAction
{
    public function __construct(private readonly AllocateSpendDayAction $allocate) {}

    /**
     * @param  list<array{campaign_id: int|null, site_ref: string, zone_ref: string, amount: string, clicks?: int|null, impressions?: int|null}>  $rows
     * @return bool whether anything changed
     */
    public function handle(int $sourceId, string $day, TrafficSpendOrigin $origin, array $rows, ?int $importId = null, ?int $userId = null): bool
    {
        $existing = TrafficSpendDay::query()->where('source_id', $sourceId)->where('network_day', $day)->where('origin', $origin)->orderBy('id')->get();
        $key = fn (array $row): string => implode('|', [$row['campaign_id'] ?? '', $row['site_ref'], $row['zone_ref'], TrafficMoney::stored($row['amount']), $row['clicks'] ?? '', $row['impressions'] ?? '']);
        $before = $existing->map(fn (TrafficSpendDay $fact): string => $key([
            'campaign_id' => $fact->campaign_id, 'site_ref' => $fact->site_ref, 'zone_ref' => $fact->zone_ref, 'amount' => $fact->amount,
            'clicks' => $fact->reported_clicks, 'impressions' => $fact->reported_impressions,
        ]))->sort()->values()->all();
        $after = collect($rows)->map($key)->sort()->values()->all();

        if ($before === $after) {
            return false;
        }

        TrafficSpendDay::query()->where('source_id', $sourceId)->where('network_day', $day)->where('origin', $origin)->delete();

        foreach ($rows as $row) {
            TrafficSpendDay::query()->create([
                'source_id' => $sourceId,
                'network_day' => $day,
                'origin' => $origin,
                'campaign_id' => $row['campaign_id'] ?? null,
                'site_ref' => $row['site_ref'],
                'zone_ref' => $row['zone_ref'],
                'amount' => TrafficMoney::stored($row['amount']),
                'reported_clicks' => $row['clicks'] ?? null,
                'reported_impressions' => $row['impressions'] ?? null,
                'import_id' => $importId,
                'entered_by_user_id' => $userId,
            ]);
        }

        // The day is shared out again, whichever origin it is now counted from.
        TrafficSpendDay::query()->where('source_id', $sourceId)->where('network_day', $day)->update(['allocated_at' => null]);

        // With no figure left for the day, no row would bring it back to be shared out: its clicks go back to their own prices now.
        $source = TrafficSource::query()->find($sourceId);

        if ($source !== null && ! TrafficSpendDay::query()->where('source_id', $sourceId)->where('network_day', $day)->exists()) {
            $this->allocate->handle($source, $day, CarbonImmutable::now());
        }

        return true;
    }
}
