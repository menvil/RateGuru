<?php

namespace App\Console\Commands;

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsGameDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsPid;
use App\Models\AnalyticsVisit;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPostback;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\TrafficVisitPost;
use App\Models\UserActivityDay;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes what the tracker recorded once it is past its retention
 * (config/traffic.php): visits after traffic.retention_days.visits (400) with
 * their conversions and Analytics' own rows of them, and each browser's first
 * day, the cells of each day's people and the sums of its games' visits once
 * every visit of their day is gone — a day the cutoff runs through is marked
 * for analytics:refresh-visits to write again from the visits left — and a
 * browser's number once no visit of it is left, a click's details, the posts a visit opened and shared
 * and the postback outbox after 90, spend figures and the block log after
 * 400, and every chance working-out but the newest 30 — and the days members
 * did something on the site after analytics.retention_days.activity (400,
 * config/analytics.php). Sources, campaigns, blocks, prices and attributions
 * stay.
 *
 * In small batches by primary key, the cutoffs fixed when the run starts; a
 * retention that is not a positive number of days fails the run without
 * deleting anything.
 */
final class TrafficPurgeExpiredCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 10_000;

    protected $signature = 'traffic:purge-expired
        {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Delete visits, postbacks and other traffic records older than their configured retention.';

    public function handle(): int
    {
        $days = [];

        foreach (['visits', 'visit_details', 'postbacks', 'spend_days', 'block_events'] as $key) {
            $value = filter_var(config('traffic.retention_days.'.$key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($value === false) {
                $this->error("traffic.retention_days.{$key} must be a positive number of days.");

                return self::FAILURE;
            }

            $days[$key] = $value;
        }

        $activityDays = filter_var(config('analytics.retention_days.activity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($activityDays === false) {
            $this->error('analytics.retention_days.activity must be a positive number of days.');

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_CHUNK_SIZE]]);

        if ($chunk === false) {
            $this->error('The --chunk option must be a whole number from 1 to '.self::MAX_CHUNK_SIZE.'.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        [$lastGone, $cut] = self::networkDays($now->subDays($days['visits']));
        $deleted = [
            'click details' => $this->purge(TrafficVisitDetail::query()->where('created_at', '<', $now->subDays($days['visit_details'])), $chunk, 'visit_id'),
            'posts of visits' => $this->purge(TrafficVisitPost::query()->where('first_at', '<', $now->subDays($days['visit_details'])), $chunk, 'visit_id'),
            'postbacks' => $this->purge(TrafficPostback::query()->where('created_at', '<', $now->subDays($days['postbacks'])), $chunk),
            'visits' => $this->purge(TrafficVisit::query()->where('arrived_at', '<', $now->subDays($days['visits'])), $chunk),
            // Analytics' own row of a visit goes with it; a browser's first day goes once visits that old are gone.
            'first days of browsers' => $this->purge(AnalyticsPerson::query()->where('first_day', '<', $now->subDays($days['visits'])->format('Y-m-d')), $chunk, 'visitor_key'),
            // The cells of a day go with its visits; a browser's number once no row of Analytics names it — never the
            // highest, which the next number counts on from: a number given again would make two browsers one person.
            'cells of people' => $this->purge(AnalyticsCell::query()->where('network_day', '<=', $lastGone), $chunk),
            'cells of accounts' => $this->purge(AnalyticsCellAccount::query()->where('network_day', '<=', $lastGone), $chunk),
            'sums of game days' => $this->purge(AnalyticsGameDay::query()->where('network_day', '<=', $lastGone), $chunk),
            'browser numbers' => $this->purge(AnalyticsPid::query()
                ->where('pid', '<', (int) AnalyticsPid::query()->max('pid'))
                ->whereNotExists(fn ($visit) => $visit->from((new AnalyticsVisit)->getTable())->whereColumn('analytics_visits.visitor_key', 'analytics_pids.visitor_key')), $chunk, 'visitor_key'),
            'spend figures' => $this->purge(TrafficSpendDay::query()->where('network_day', '<', $now->subDays($days['spend_days'])->format('Y-m-d')), $chunk),
            'block log lines' => $this->purge(TrafficBlockEvent::query()->where('occurred_at', '<', $now->subDays($days['block_events'])), $chunk),
            'activity days' => $this->purge(UserActivityDay::query()->where('day', '<', $now->subDays($activityDays)->format('Y-m-d')), $chunk),
        ];

        foreach ($cut as $day) {
            app(MarkAnalyticsDayAction::class)->handle($day, $now);
        }

        // Only runs older than the oldest one kept: a working-out begun since is newer still, and stays.
        $oldestKept = TrafficChanceRun::query()->orderByDesc('id')->limit(max(1, (int) config('traffic.chance_runs_kept')))->pluck('id')->min();
        $deleted['chance runs'] = $oldestKept === null ? 0 : $this->purge(TrafficChanceRun::query()->where('id', '<', $oldestKept), $chunk);

        foreach ($deleted as $what => $count) {
            $this->info("Deleted {$count} {$what}.");
        }

        return self::SUCCESS;
    }

    /**
     * The network days a cutoff of the visits leaves whole or empty: the last
     * whose visits all arrived before it, and those it runs through — a
     * network day's visits arrive over more than its UTC day (TrafficReportFilter),
     * so some of theirs went and some stay.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function networkDays(CarbonImmutable $cutoff): array
    {
        $lastGone = $cutoff->subDays(4)->toDateString();
        $cut = [];

        for ($day = $cutoff->subDays(3); $day->lessThanOrEqualTo($cutoff->addDays(3)); $day = $day->addDay()) {
            $window = new TrafficReportFilter($day->toDateString(), $day->toDateString());

            if ($window->arrivedBefore()->lessThanOrEqualTo($cutoff)) {
                $lastGone = $day->toDateString();
            } elseif ($window->arrivedFrom()->lessThan($cutoff)) {
                $cut[] = $day->toDateString();
            }
        }

        return [$lastGone, $cut];
    }

    /** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $expired */
    private function purge(Builder $expired, int $chunk, string $key = 'id'): int
    {
        $deleted = 0;

        do {
            $ids = (clone $expired)->orderBy($key)->limit($chunk)->pluck($key);

            if ($ids->isEmpty()) {
                break;
            }

            // Still past its retention: a key a table shares among its rows takes only those.
            $deleted += (clone $expired)->whereIn($key, $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
