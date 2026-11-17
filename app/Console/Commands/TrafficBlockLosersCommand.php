<?php

namespace App\Console\Commands;

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficSourceStatus;
use App\Models\TrafficBlock;
use App\Models\TrafficChanceRun;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficStepChance;
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
 * click in the last 3 days, the most spent first, no more a day than the
 * source allows; a weak start stays a hint. The blocks are queued for
 * traffic:sync-networks to apply.
 */
final class TrafficBlockLosersCommand extends Command
{
    protected $signature = 'traffic:block-losers';

    protected $description = 'Queue blocks of the sites and zones the block rule calls bots or losing.';

    public function handle(TrafficReportQuery $reports, BlockTrafficRefsAction $blocking, ConnectorRegistry $registry): int
    {
        $now = CarbonImmutable::now();
        $settings = TrafficSetting::current();
        $rule = $settings->only(['block_min_spend', 'block_min_clicks', 'block_roi_below', 'block_open_below', 'block_bot_clicks', 'block_start_clicks', 'block_start_percent']);
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
        $worthKnown = $run !== null && (int) TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->where('step', 'opened')->value('accounts') >= 30;
        $month = TrafficReportFilter::lastDays(30, $now);
        $recent = TrafficReportFilter::lastDays(3, $now);
        $queued = 0;

        foreach (TrafficSource::query()->where('status', TrafficSourceStatus::Active)->where('block_auto', true)->whereNotNull('connector')->get() as $source) {
            if (! $registry->verified($source->connector) || ! $blocking->canBlockViaApi($source, $source->block_level)) {
                continue;
            }

            $byZone = $source->block_level === TrafficBlockLevel::Zone;
            $filter = new TrafficReportFilter($month->fromDay, $month->toDay, ['source_id' => $source->id]);
            $rows = $reports->zones($filter, $byZone);
            $active = collect($reports->zones(new TrafficReportFilter($recent->fromDay, $recent->toDay, ['source_id' => $source->id]), $byZone))
                ->map(fn (array $row): string => (string) ($byZone ? $row['zone_ref'] : $row['site_ref']))->flip();
            $totals = array_reduce($rows, fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures);
            $sourceValue = $totals->value();
            $blocked = TrafficBlock::query()->where('source_id', $source->id)->where('level', $source->block_level)->pluck('ref')->flip();
            $today = TrafficBlock::query()->where('source_id', $source->id)->where('method', TrafficBlockMethod::Auto)->where('blocked_at', '>=', $now->utc()->startOfDay())->count();
            $room = max(0, $source->block_daily_cap - $today);
            $candidates = [];

            foreach ($rows as $row) {
                $ref = (string) ($byZone ? $row['zone_ref'] : $row['site_ref']);

                if ($ref === '' || isset($blocked[$ref]) || ! isset($active[$ref])) {
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

        $this->info("Queued {$queued} blocks.");

        return self::SUCCESS;
    }
}
