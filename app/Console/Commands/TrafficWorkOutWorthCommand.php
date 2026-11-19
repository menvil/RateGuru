<?php

namespace App\Console\Commands;

use App\Actions\Traffic\RevalueOpenVisitsAction;
use App\Actions\Traffic\WorkOutChancesAction;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPriceSet;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Works out what each step is worth from the matured visits, then brings the
 * open visits' worth up to date (docs/architecture/traffic.md, "Worth").
 * Nightly; and every five minutes with --when-groups-changed, which does the
 * work only when the country groups were changed since the last run.
 */
final class TrafficWorkOutWorthCommand extends Command
{
    protected $signature = 'traffic:work-out-worth {--when-groups-changed : Only when the country groups changed since the last run}';

    protected $description = 'Work out what each step of the traffic is worth, and revalue the open visits.';

    public function handle(WorkOutChancesAction $chances, RevalueOpenVisitsAction $revalue): int
    {
        $whenChanged = (bool) $this->option('when-groups-changed');

        if ($whenChanged && ! $this->groupsChanged()) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $run = $chances->handle($whenChanged ? 'groups' : 'nightly', $now);
        $changed = $revalue->handle($now);

        $this->info("Worked out from {$run->visits_scanned} visits; {$changed} open visits revalued.");

        return self::SUCCESS;
    }

    private function groupsChanged(): bool
    {
        $last = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();

        if ($last === null) {
            return false;
        }

        $current = TrafficPriceSet::current();

        return $current->id !== $last->price_set_id
            && $current->country_groups !== TrafficPriceSet::query()->find($last->price_set_id)?->country_groups;
    }
}
