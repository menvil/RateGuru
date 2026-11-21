<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsPerson;
use App\Models\AnalyticsVisit;
use App\Queries\Analytics\AnalyticsRollupQuery;
use App\Support\Analytics\AnalyticsVisitRow;
use Illuminate\Support\Facades\DB;

/**
 * Brings the rows Growth → Analytics reads of one network day up to date
 * with its visits (docs/architecture/analytics.md, "What a tab reads"): a
 * block of visits at a time, each made into its row and compared with the
 * one written before, so only a row that changed — or is new — is written
 * again; and each browser whose page ran that day given that day as its
 * first, unless an earlier one is already known. Run again on the same day
 * it writes nothing. Called by analytics:refresh-visits only.
 */
final class RefreshAnalyticsDayAction
{
    /** Visits read at a time. */
    private const int BLOCK = 2000;

    /** Rows written in one statement: well under every engine's limit on bound values. */
    private const int WRITE = 400;

    public function __construct(private readonly AnalyticsRollupQuery $rollup) {}

    /** @return int how many rows were written */
    public function handle(string $day): int
    {
        $written = 0;
        $afterId = 0;

        do {
            $visits = $this->rollup->visits($day, $afterId, self::BLOCK);

            if ($visits === []) {
                break;
            }

            $rows = [];

            foreach ($visits as $visit) {
                $row = AnalyticsVisitRow::fromVisit($visit);
                $rows[(int) $row['id']] = $row;
            }

            $afterId = (int) array_key_last($rows);
            $before = $this->rollup->written(array_keys($rows));
            $changed = array_values(array_filter($rows, fn (array $row): bool => ! isset($before[(int) $row['id']]) || ! AnalyticsVisitRow::same($before[(int) $row['id']], $row)));
            $firstDays = $this->firstDays($day, $rows);

            if ($changed === [] && $firstDays === []) {
                continue;
            }

            DB::transaction(function () use ($changed, $firstDays): void {
                foreach (array_chunk($changed, self::WRITE) as $block) {
                    AnalyticsVisit::query()->upsert($block, ['id'], array_values(array_diff(AnalyticsVisitRow::COLUMNS, ['id'])));
                }

                foreach (array_chunk($firstDays, self::WRITE) as $block) {
                    AnalyticsPerson::query()->upsert($block, ['visitor_key'], ['first_day']);
                }
            });

            $written += count($changed);
        } while (count($visits) === self::BLOCK);

        return $written;
    }

    /**
     * The browsers whose page ran on the day among the rows given, with the
     * day as their first, that no earlier day is known for.
     *
     * @param  array<int, array<string, string|null>>  $rows
     * @return list<array{visitor_key: int, first_day: string}>
     */
    private function firstDays(string $day, array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            if ($row['opened'] === '1') {
                $keys[(int) $row['visitor_key']] = true;
            }
        }

        $known = $this->rollup->firstDays(array_keys($keys));
        $first = [];

        foreach (array_keys($keys) as $key) {
            if (! isset($known[$key]) || $known[$key] > $day) {
                $first[] = ['visitor_key' => $key, 'first_day' => $day];
            }
        }

        return $first;
    }
}
