<?php

namespace App\Console\Commands;

use App\Models\TrafficBlockEvent;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPostback;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\UserActivityDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes what the tracker recorded once it is past its retention
 * (config/traffic.php): visits after traffic.retention_days.visits (400) with
 * their conversions, a click's details and the postback outbox after 90,
 * spend figures and the block log after 400, and every chance working-out
 * but the newest 30 — and the days members did something on the site after
 * analytics.retention_days.activity (400, config/analytics.php). Sources,
 * campaigns, blocks, prices and attributions stay.
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
        $deleted = [
            'click details' => $this->purge(TrafficVisitDetail::query()->where('created_at', '<', $now->subDays($days['visit_details'])), $chunk, 'visit_id'),
            'postbacks' => $this->purge(TrafficPostback::query()->where('created_at', '<', $now->subDays($days['postbacks'])), $chunk),
            'visits' => $this->purge(TrafficVisit::query()->where('arrived_at', '<', $now->subDays($days['visits'])), $chunk),
            'spend figures' => $this->purge(TrafficSpendDay::query()->where('network_day', '<', $now->subDays($days['spend_days'])->format('Y-m-d')), $chunk),
            'block log lines' => $this->purge(TrafficBlockEvent::query()->where('occurred_at', '<', $now->subDays($days['block_events'])), $chunk),
            'activity days' => $this->purge(UserActivityDay::query()->where('day', '<', $now->subDays($activityDays)->format('Y-m-d')), $chunk),
        ];

        $kept = TrafficChanceRun::query()->orderByDesc('id')->limit(max(1, (int) config('traffic.chance_runs_kept')))->pluck('id');
        $deleted['chance runs'] = $kept->isEmpty() ? 0 : $this->purge(TrafficChanceRun::query()->whereNotIn('id', $kept), $chunk);

        foreach ($deleted as $what => $count) {
            $this->info("Deleted {$count} {$what}.");
        }

        return self::SUCCESS;
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

            $deleted += $expired->getModel()->newQuery()->whereIn($key, $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
