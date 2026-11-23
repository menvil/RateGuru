<?php

namespace App\Actions\Traffic;

use App\Models\TrafficSpendDayCountry;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Puts a source's impressions by country for one day in place of the ones it
 * had. The same figures again write nothing, as a turn of the sync reads the
 * same days every five minutes. Spend is not touched: it is shared out from
 * the day's own figures.
 */
final class ReplaceSpendCountriesAction
{
    private const int INSERT_CHUNK = 200;

    /**
     * @param  list<array{campaign_id: int, site_ref: string, country: string, impressions: int, amount: string}>  $rows
     * @return bool whether anything changed
     */
    public function handle(int $sourceId, string $day, array $rows): bool
    {
        $merged = [];

        foreach ($rows as $row) {
            $slice = implode('|', [$row['campaign_id'], $row['site_ref'], $row['country']]);
            $merged[$slice] ??= [...$row, 'impressions' => 0, 'amount' => BigDecimal::zero()];
            $merged[$slice]['impressions'] += $row['impressions'];
            $merged[$slice]['amount'] = $merged[$slice]['amount']->plus(TrafficMoney::of($row['amount']));
        }

        $existing = TrafficSpendDayCountry::query()->where('source_id', $sourceId)->where('network_day', $day)->get();
        $before = $existing->map(fn (TrafficSpendDayCountry $figure): string => implode('|', [$figure->campaign_id, $figure->site_ref, $figure->country, $figure->impressions, TrafficMoney::stored($figure->amount)]))->sort()->values()->all();
        $after = collect($merged)->map(fn (array $row): string => implode('|', [$row['campaign_id'], $row['site_ref'], $row['country'], $row['impressions'], TrafficMoney::stored($row['amount'])]))->sort()->values()->all();

        if ($before === $after) {
            return false;
        }

        $now = now();
        $inserts = array_map(fn (array $row): array => [
            'source_id' => $sourceId,
            'network_day' => $day,
            'campaign_id' => $row['campaign_id'],
            'site_ref' => $row['site_ref'],
            'country' => $row['country'],
            'impressions' => $row['impressions'],
            'amount' => TrafficMoney::stored($row['amount']),
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($merged));

        DB::transaction(function () use ($sourceId, $day, $inserts): void {
            TrafficSpendDayCountry::query()->where('source_id', $sourceId)->where('network_day', $day)->delete();

            foreach (array_chunk($inserts, self::INSERT_CHUNK) as $chunk) {
                TrafficSpendDayCountry::query()->insert($chunk);
            }
        });

        return true;
    }
}
