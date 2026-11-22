<?php

namespace App\Console\Commands;

use App\Filament\Pages\Analytics\AnalyticsReadCache;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Reads the figures of Growth → Analytics ahead of the administrators
 * (docs/architecture/analytics.md, "The figures kept, and read ahead"): every
 * five minutes,
 * each tab slow to read (GrowthAnalyticsPage::READ_CACHED) with what its
 * filters offer, for the views likeliest to be opened
 * (AnalyticsReadCache::recentViews()), each read as the administrator who
 * last opened it reads it, kept for everyone under the very keys the page
 * asks for (GrowthAnalyticsPage::readAhead()). Figures still kept
 * past the next run are not read again. Nothing is read once no
 * administrator opened the page for `analytics.read_ahead.idle_hours`, nor
 * while the tabs read the visits themselves rather than Analytics' own rows
 * (AnalyticsRollupGate::reads()): every read would then be the slowest there
 * is, for nobody yet. No new read starts after --seconds, so a run ends
 * before the next is due; each run logs how long it took.
 */
final class AnalyticsWarmFiguresCommand extends Command
{
    /** How often the schedule runs it, in minutes: figures kept a minute past the next run are not read again. */
    public const int EVERY_MINUTES = 5;

    protected $signature = 'analytics:warm-figures {--seconds=240 : Stop starting new reads after so many seconds}';

    protected $description = 'Read the figures of Growth → Analytics ahead of the administrators.';

    public function handle(AnalyticsReadCache $cache, AnalyticsRollupGate $gate, DomainLogger $logger): int
    {
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);

        if ($seconds === false) {
            $this->error('--seconds takes 1 to 3600.');

            return self::FAILURE;
        }

        if ((int) config('analytics.read_cache_minutes') <= 0) {
            $this->info('No figures are kept, so none are read ahead.');

            return self::SUCCESS;
        }

        $views = $cache->recentViews();

        if ($views === []) {
            $this->info('No administrator opened Analytics in the last '.(int) config('analytics.read_ahead.idle_hours').' hours; nothing read ahead.');

            return self::SUCCESS;
        }

        if (! $gate->reads()) {
            $this->info('Analytics reads the visits themselves until its own rows are current; nothing read ahead.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $ahead = AnalyticsReadCache::ahead(CarbonImmutable::now('UTC')->addMinutes(self::EVERY_MINUTES + 1));
        $failed = 0;
        $skipped = 0;
        $signedIn = Auth::hasUser() ? Auth::user() : null;
        app()->instance(AnalyticsReadCache::class, $ahead);

        try {
            // Every view's first tab before any view's second: the tab a page opens on is the likeliest asked for.
            foreach (GrowthAnalyticsPage::READ_CACHED as $tab) {
                foreach ($views as $view) {
                    if (microtime(true) - $started >= $seconds) {
                        $skipped++;

                        continue;
                    }

                    self::addressedAt($view['origin']);
                    // As the administrator it is read for reads it: what a tab shows may depend on what they may open.
                    Auth::setUser($view['reader']);

                    try {
                        GrowthAnalyticsPage::readAhead($tab, $view['period'], $view['from'], $view['to']);
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            }
        } finally {
            app()->forgetInstance(AnalyticsReadCache::class);
            self::addressedAt(null);
            $signedIn === null ? Auth::forgetUser() : Auth::setUser($signedIn);
        }

        ['read' => $read, 'kept' => $kept] = $ahead->tally();
        $milliseconds = (int) round((microtime(true) - $started) * 1000);
        $logger->info('analytics.figures.read_ahead', ['views' => count($views), 'read' => $read, 'kept' => $kept, 'failed' => $failed, 'skipped' => $skipped, 'ms' => $milliseconds]);
        $this->info("Read {$read} figures ahead for ".count($views).' views in '.$milliseconds.' ms; '.$kept.' were kept already'
            .($skipped > 0 ? ", {$skipped} tab views left for lack of time" : '').($failed > 0 ? ", {$failed} failed" : '').'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Every address built from now on as a request to the site's address given builds it; none, as configured. */
    private static function addressedAt(?string $origin): void
    {
        URL::useOrigin($origin);
        URL::forceScheme($origin === null ? null : (parse_url($origin, PHP_URL_SCHEME) ?: null));
    }
}
