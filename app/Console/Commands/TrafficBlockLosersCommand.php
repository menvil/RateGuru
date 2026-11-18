<?php

namespace App\Console\Commands;

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficSourceStatus;
use App\Models\TrafficBlock;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Queries\Traffic\TrafficBlocksQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Support\Traffic\Blocking\BlockJudge;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Blocks by itself the sites or zones the block rule calls bots or losing,
 * for the sources set to (docs/architecture/traffic.md, "Blocking"): active,
 * connected to a verified API that blocks their level. Only ones with a
 * click in the last 3 days, the most spent first, no more a UTC day than the
 * source allows; never one blocked or queued already, nor one an
 * administrator unblocked in the last 30 days; a weak start stays a hint.
 * The blocks are queued for traffic:sync-networks to apply. Scheduled every
 * five minutes: with no source set to block by itself a run reads no figures
 * and only records that it ran, for the sources' Blocking pane.
 */
final class TrafficBlockLosersCommand extends Command
{
    protected $signature = 'traffic:block-losers';

    protected $description = 'Queue blocks of the sites and zones the block rule calls bots or losing.';

    public function handle(TrafficReportQuery $reports, TrafficBlocksQuery $blocks, BlockTrafficRefsAction $blocking, ConnectorRegistry $registry): int
    {
        $now = CarbonImmutable::now();
        $sources = TrafficSource::query()->where('status', TrafficSourceStatus::Active)->where('block_auto', true)->whereNotNull('connector')->get()
            ->filter(fn (TrafficSource $source): bool => $registry->verified($source->connector) && $blocking->canBlockViaApi($source, $source->block_level));
        $queued = 0;

        if ($sources->isNotEmpty()) {
            $settings = TrafficSetting::current();
            $rule = $settings->only(['block_min_spend', 'block_min_clicks', 'block_roi_below', 'block_open_below', 'block_bot_clicks', 'block_start_clicks', 'block_start_percent']);
            $worthKnown = $blocks->worthAccounts() >= TrafficBlocksQuery::WORTH_KNOWN_AT;
            $month = TrafficReportFilter::lastDays(30, $now);
            $recent = TrafficReportFilter::lastDays(3, $now);

            foreach ($sources as $source) {
                $byZone = $source->block_level === TrafficBlockLevel::Zone;
                $filter = new TrafficReportFilter($month->fromDay, $month->toDay, ['source_id' => $source->id]);
                $rows = $reports->zones($filter, $byZone);
                $active = collect($reports->zones(new TrafficReportFilter($recent->fromDay, $recent->toDay, ['source_id' => $source->id]), $byZone))
                    ->map(fn (array $row): string => (string) ($byZone ? $row['zone_ref'] : $row['site_ref']))->flip();
                $totals = array_reduce($rows, fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures);
                $sourceValue = $totals->value();
                // Blocked or queued already, in any state, or unblocked by an administrator lately: left as it is.
                $leave = TrafficBlock::query()->where('source_id', $source->id)->where('level', $source->block_level)->pluck('ref')
                    ->merge($blocks->unblockedLately((int) $source->id, $source->block_level, $now))->flip();
                $room = max(0, $source->block_daily_cap - $blocks->blockedByRuleToday((int) $source->id, $now));
                $candidates = [];

                foreach ($rows as $row) {
                    $ref = (string) ($byZone ? $row['zone_ref'] : $row['site_ref']);

                    if ($ref === '' || isset($leave[$ref]) || ! isset($active[$ref])) {
                        continue;
                    }

                    $verdict = BlockJudge::judge($row['figures'], $totals->startRate(), $worthKnown && $sourceValue->isPositive(), $rule)['verdict'];

                    if (in_array($verdict, [TrafficBlockVerdict::Bots, TrafficBlockVerdict::Losing], true)) {
                        $candidates[] = ['ref' => $ref, 'verdict' => $verdict, 'spend' => (float) $row['figures']->spend];
                    }
                }

                usort($candidates, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

                foreach (collect(array_slice($candidates, 0, $room))->groupBy(fn (array $candidate): string => $candidate['verdict']->value) as $verdict => $group) {
                    $queued += $blocking->block(null, $source, $source->block_level, $group->pluck('ref')->all(), null, true, TrafficBlockMethod::Auto, TrafficBlockVerdict::from($verdict), 'Block rule');
                }
            }
        }

        TrafficSetting::recordBlockRuleRun($now);
        $this->info("Queued {$queued} blocks.");

        return self::SUCCESS;
    }
}
