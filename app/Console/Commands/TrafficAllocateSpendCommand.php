<?php

namespace App\Console\Commands;

use App\Actions\Traffic\AllocateSpendDayAction;
use App\Actions\Traffic\MaterializeFixedSpendAction;
use App\Enums\TrafficSpendMode;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Shares spend figures out over the visits they cover, outside any web
 * request (docs/architecture/traffic.md, "Spend"). Every minute, for up to
 * 45 seconds: placements' fixed prices kept up to today, then every day
 * whose figures changed, and a day still going on — whose visits keep
 * arriving — again every 15 minutes.
 */
final class TrafficAllocateSpendCommand extends Command
{
    protected $signature = 'traffic:allocate-spend';

    protected $description = 'Share the traffic sources\' spend figures out over their visits.';

    private const int SECONDS = 45;

    private const int OPEN_DAY_MINUTES = 15;

    public function handle(AllocateSpendDayAction $allocate, MaterializeFixedSpendAction $fixed): int
    {
        $deadline = microtime(true) + self::SECONDS;
        $now = CarbonImmutable::now();

        foreach (TrafficSource::query()->where('spend_mode', TrafficSpendMode::Fixed)->get() as $source) {
            $fixed->handle($source, CarbonImmutable::now($source->reporting_timezone)->subDay()->toDateString());
        }

        $sources = TrafficSource::query()->get()->keyBy('id');
        $slices = TrafficSpendDay::query()
            ->where(fn ($due) => $due
                ->whereNull('allocated_at')
                ->orWhere(fn ($open) => $open->where('network_day', '>=', $now->subDays(2)->toDateString())->where('allocated_at', '<', $now->subMinutes(self::OPEN_DAY_MINUTES))))
            ->select('source_id', 'network_day')
            ->distinct()
            ->orderBy('network_day')
            ->get();
        $done = 0;

        foreach ($slices as $slice) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $source = $sources->get($slice->source_id);

            if ($source === null) {
                continue;
            }

            $dayEnds = CarbonImmutable::parse((string) $slice->network_day, $source->reporting_timezone)->addDay()->startOfDay();
            $facts = TrafficSpendDay::query()->where('source_id', $source->id)->where('network_day', $slice->network_day);
            $stale = (clone $facts)->whereNull('allocated_at')->exists();
            $open = $dayEnds->greaterThan($now->subMinutes(self::OPEN_DAY_MINUTES));
            // Shared out last while the day still ran: once more now it is over, so a fixed price stops being an estimate.
            $before = (clone $facts)->min('allocated_at');
            $endedSince = $before !== null && CarbonImmutable::parse((string) $before)->lessThan($dayEnds) && ! $open;

            if (! $stale && ! $open && ! $endedSince) {
                continue;
            }

            // One day that can't be shared out must not hold back every day after it.
            try {
                $allocate->handle($source, (string) $slice->network_day, $now);
                $done++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Shared out {$done} days of spend.");

        return self::SUCCESS;
    }
}
