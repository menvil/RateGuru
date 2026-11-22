<?php

namespace App\Console\Commands;

use App\Actions\Analytics\RefreshAnalyticsGameCellsAction;
use App\Actions\Analytics\RefreshAnalyticsPeriodHoursAction;
use App\Actions\Analytics\RefreshAnalyticsVisitsAction;
use App\Actions\Traffic\CopyCreditedKeysAction;
use Closure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings the rows Growth → Analytics reads up to date with the visits
 * (docs/architecture/analytics.md, "What a tab reads"): every minute for up
 * to 45 seconds — the days still changing, the older days marked as
 * changed, and the days not filled yet — and, once a night, the last
 * --recheck days again. First, what Retention reads of an account's credit
 * is copied onto any attribution written without it (CopyCreditedKeysAction),
 * the hours of assignments a traffic period's funnel is summed from are
 * counted (RefreshAnalyticsPeriodHoursAction), and the people of the games'
 * days (RefreshAnalyticsGameCellsAction): a few reads a minute, which a
 * game's figures read from the assignments, events and attempts until they
 * are done.
 */
final class AnalyticsRefreshVisitsCommand extends Command
{
    protected $signature = 'analytics:refresh-visits {--recheck=0 : Read the last so many days again} {--seconds=45 : Stop starting new days after so many seconds}';

    protected $description = 'Bring the visits Growth → Analytics reads up to date.';

    public function handle(RefreshAnalyticsVisitsAction $refresh, RefreshAnalyticsPeriodHoursAction $periodHours, RefreshAnalyticsGameCellsAction $gameCells, CopyCreditedKeysAction $credits): int
    {
        $recheck = filter_var($this->option('recheck'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 400]]);
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);

        if ($recheck === false || $seconds === false) {
            $this->error('--recheck takes 0 to 400 days and --seconds 1 to 86400.');

            return self::FAILURE;
        }

        $deadline = microtime(true) + $seconds;
        $hours = 0;
        $gameDays = 0;
        // Each step before the visits on its own: one that fails is reported, and the visits every tab reads are still brought up to date.
        $ran = [
            self::aside(fn () => $credits->handle()),
            self::aside(function () use ($periodHours, $deadline, $recheck, &$hours): void {
                $hours = $periodHours->handle($deadline, $recheck);
            }),
            self::aside(function () use ($gameCells, $deadline, $recheck, &$gameDays): void {
                $gameDays = $gameCells->handle($deadline, $recheck);
            }),
        ];
        $done = $refresh->handle($deadline, $recheck);
        $this->info("Counted {$hours} hours of assignments and {$gameDays} days of games' people. Read {$done['days']} days, wrote {$done['written']} rows".($done['filled'] ? '.' : '; still filling the earlier days.'));

        if (in_array(false, $ran, true)) {
            $this->error('A step before the visits failed and was reported; the visits were brought up to date.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Runs a step, a failure of it reported rather than thrown: whether it ran through. */
    private static function aside(Closure $step): bool
    {
        try {
            $step();

            return true;
        } catch (Throwable $failure) {
            report($failure);

            return false;
        }
    }
}
