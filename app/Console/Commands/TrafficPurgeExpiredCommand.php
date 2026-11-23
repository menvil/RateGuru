<?php

namespace App\Console\Commands;

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsFirstPostCell;
use App\Models\AnalyticsGameDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsPid;
use App\Models\AnalyticsPostCell;
use App\Models\AnalyticsVisit;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPostback;
use App\Models\TrafficSpendDay;
use App\Models\TrafficSpendDayCountry;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\TrafficVisitPost;
use App\Models\UserActivityDay;
use App\Queries\Analytics\AnalyticsRollupQuery;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes what the tracker recorded once it is past its retention
 * (config/traffic.php): visits after traffic.retention_days.visits (400) with
 * their conversions and Analytics' own rows of them — each browser's first
 * day moved on to the earliest of its visits left, or gone with the last of
 * them — and the cells of each day's people, posts and first posts and the sums of
 * its games' visits once every visit of their day is gone — a day the cutoff
 * runs through is marked for analytics:refresh-visits to write again from the
 * visits left — and a browser's number once no visit of it is left; a
 * click's details, the posts a visit opened and shared — the days of their
 * visits marked, as Analytics keeps what each visit did with posts, and of
 * the visits credited with an account in their sessions, which keep the post
 * the session opened first — and the postback outbox after 90, spend figures
 * and the block log after 400, and every chance working-out but the newest
 * 30 — and the days members did something on the site after
 * analytics.retention_days.activity (400, config/analytics.php). Sources,
 * campaigns, blocks, prices and attributions stay.
 *
 * In small batches by primary key, the cutoffs fixed when the run starts; a
 * batch of the posts of visits marks its days in the transaction that
 * deletes it, so a run that fails part way leaves no posts gone with their
 * days unmarked. A retention that is not a positive number of days fails the
 * run without deleting anything.
 */
final class TrafficPurgeExpiredCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 10_000;

    /** First days moved on in one statement: well under every engine's limit on bound values. */
    private const int FIRST_DAYS_WRITE = 400;

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
            'posts of visits' => $this->purge(TrafficVisitPost::query()->where('first_at', '<', $now->subDays($days['visit_details'])), $chunk, 'visit_id', self::postDays(...), $now),
            'postbacks' => $this->purge(TrafficPostback::query()->where('created_at', '<', $now->subDays($days['postbacks'])), $chunk),
            'visits' => $this->purge(TrafficVisit::query()->where('arrived_at', '<', $now->subDays($days['visits'])), $chunk),
            // Analytics' own row of a visit goes with it; a browser's first day moves on once visits that old are gone.
            'first days of browsers' => $this->purgeFirstDays($now->subDays($days['visits'])->format('Y-m-d'), $chunk),
            // The cells of a day go with its visits; a browser's number once no row of Analytics names it — never the
            // highest, which the next number counts on from: a number given again would make two browsers one person.
            'cells of people' => $this->purge(AnalyticsCell::query()->where('network_day', '<=', $lastGone), $chunk),
            'cells of accounts' => $this->purge(AnalyticsCellAccount::query()->where('network_day', '<=', $lastGone), $chunk),
            'sums of game days' => $this->purge(AnalyticsGameDay::query()->where('network_day', '<=', $lastGone), $chunk),
            'cells of posts' => $this->purge(AnalyticsPostCell::query()->where('network_day', '<=', $lastGone), $chunk),
            'cells of first posts' => $this->purge(AnalyticsFirstPostCell::query()->where('network_day', '<=', $lastGone), $chunk),
            'browser numbers' => $this->purge(AnalyticsPid::query()
                ->where('pid', '<', (int) AnalyticsPid::query()->max('pid'))
                ->whereNotExists(fn ($visit) => $visit->from((new AnalyticsVisit)->getTable())->whereColumn('analytics_visits.visitor_key', 'analytics_pids.visitor_key')), $chunk, 'visitor_key'),
            'spend figures' => $this->purge(TrafficSpendDay::query()->where('network_day', '<', $now->subDays($days['spend_days'])->format('Y-m-d')), $chunk),
            'spend figures by country' => $this->purge(TrafficSpendDayCountry::query()->where('network_day', '<', $now->subDays($days['spend_days'])->format('Y-m-d')), $chunk),
            'block log lines' => $this->purge(TrafficBlockEvent::query()->where('occurred_at', '<', $now->subDays($days['block_events'])), $chunk),
            'activity days' => $this->purge(UserActivityDay::query()->where('day', '<', $now->subDays($activityDays)->format('Y-m-d')), $chunk),
        ];

        // A run that fails before this leaves no day lost: the next finds each again, still cut or gone whole with its cells.
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

    /**
     * The days Analytics reads again once a batch of the posts of visits goes:
     * those of the visits whose posts they are, as Analytics keeps what each
     * visit did with posts, and of the visits credited with an account in the
     * sessions of those that opened them, each keeping the post its session
     * opened first.
     *
     * @param  Builder<TrafficVisitPost>  $batch
     * @return list<string>
     */
    private static function postDays(Builder $batch): array
    {
        $visits = TrafficVisit::query()->whereIn('id', (clone $batch)->select('visit_id'))->distinct()->pluck('network_day');
        $credited = TrafficVisit::query()
            ->whereNotNull('account_at')
            ->whereIn('entry_visit_id', TrafficVisit::query()->whereIn('id', (clone $batch)->where('views', '>', 0)->select('visit_id'))->select('entry_visit_id'))
            ->distinct()
            ->pluck('network_day');

        return $visits->merge($credited)->map(fn (mixed $day): string => substr((string) $day, 0, 10))->values()->all();
    }

    /**
     * Each browser's first day from before the visits kept, once its visits
     * are gone: moved on to the earliest network day its page ran among the
     * visits left — what the rows' count of the people who came back takes
     * for seen before a period, as the visits' count reads the visits left —
     * or deleted with the last of them. The days left are read for all of
     * them at once (AnalyticsRollupQuery::firstDaysLeft()).
     */
    private function purgeFirstDays(string $before, int $chunk): int
    {
        $left = app(AnalyticsRollupQuery::class)->firstDaysLeft($before);

        foreach (array_chunk($left, self::FIRST_DAYS_WRITE, true) as $block) {
            AnalyticsPerson::query()->upsert(array_map(fn (int|string $key, string $day): array => ['visitor_key' => (int) $key, 'first_day' => $day], array_keys($block), $block), ['visitor_key'], ['first_day']);
        }

        $deleted = 0;
        $after = null;

        // By key from the last batch on: a day moved on that is still before the cutoff — a network day runs past its UTC day — stays.
        do {
            $keys = AnalyticsPerson::query()
                ->where('first_day', '<', $before)
                ->when($after !== null, fn (Builder $later): Builder => $later->where('visitor_key', '>', $after))
                ->orderBy('visitor_key')
                ->limit($chunk)
                ->pluck('visitor_key')
                ->all();

            if ($keys === []) {
                break;
            }

            $after = end($keys);
            $gone = array_values(array_filter($keys, fn (mixed $key): bool => ! isset($left[(int) $key])));
            $deleted += $gone === [] ? 0 : AnalyticsPerson::query()->whereIn('visitor_key', $gone)->where('first_day', '<', $before)->delete();
        } while (count($keys) === $chunk);

        return $deleted;
    }

    /**
     * Each batch in a transaction of its own when its rows tell Analytics the
     * days they leave changed: the days read before the rows go and marked
     * after, so a run that fails later never leaves rows gone and their days
     * unmarked — the next run would find no row to tell it them.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $expired
     * @param  (Closure(Builder<TModel>): list<string>)|null  $days  the days a batch's rows leave changed
     */
    private function purge(Builder $expired, int $chunk, string $key = 'id', ?Closure $days = null, ?CarbonImmutable $now = null): int
    {
        $deleted = 0;

        do {
            $ids = (clone $expired)->orderBy($key)->limit($chunk)->pluck($key);

            if ($ids->isEmpty()) {
                break;
            }

            // Still past its retention: a key a table shares among its rows takes only those.
            $batch = (clone $expired)->whereIn($key, $ids);

            $deleted += $days === null ? $batch->delete() : DB::transaction(function () use ($batch, $days, $now): int {
                $changed = array_unique($days(clone $batch));
                $count = $batch->delete();
                // The earliest first, so two writers marking some of the same days lock them in one order.
                sort($changed);

                foreach ($changed as $day) {
                    app(MarkAnalyticsDayAction::class)->handle($day, $now);
                }

                return $count;
            });
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
