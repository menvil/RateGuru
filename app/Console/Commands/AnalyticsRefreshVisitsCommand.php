<?php

namespace App\Console\Commands;

use App\Actions\Analytics\RefreshAnalyticsPeriodHoursAction;
use App\Actions\Analytics\RefreshAnalyticsVisitsAction;
use App\Actions\Traffic\CopyCreditedKeysAction;
use Illuminate\Console\Command;

/**
 * Brings the rows Growth → Analytics reads up to date with the visits
 * (docs/architecture/analytics.md, "What a tab reads"): every minute for up
 * to 45 seconds — the days still changing, the older days marked as
 * changed, and the days not filled yet — and, once a night, the last
 * --recheck days again. First, what Retention reads of an account's credit
 * is copied onto any attribution written without it (CopyCreditedKeysAction),
 * and the hours of assignments a traffic period's funnel is summed from are
 * counted (RefreshAnalyticsPeriodHoursAction): a few reads a minute, which the
 * funnel reads from the assignments until they are done.
 */
final class AnalyticsRefreshVisitsCommand extends Command
{
    protected $signature = 'analytics:refresh-visits {--recheck=0 : Read the last so many days again} {--seconds=45 : Stop starting new days after so many seconds}';

    protected $description = 'Bring the visits Growth → Analytics reads up to date.';

    public function handle(RefreshAnalyticsVisitsAction $refresh, RefreshAnalyticsPeriodHoursAction $periodHours, CopyCreditedKeysAction $credits): int
    {
        $recheck = filter_var($this->option('recheck'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 400]]);
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);

        if ($recheck === false || $seconds === false) {
            $this->error('--recheck takes 0 to 400 days and --seconds 1 to 86400.');

            return self::FAILURE;
        }

        $deadline = microtime(true) + $seconds;
        $credits->handle();
        $hours = $periodHours->handle($deadline, $recheck);
        $done = $refresh->handle($deadline, $recheck);
        $this->info("Counted {$hours} hours of assignments. Read {$done['days']} days, wrote {$done['written']} rows".($done['filled'] ? '.' : '; still filling the earlier days.'));

        return self::SUCCESS;
    }
}
