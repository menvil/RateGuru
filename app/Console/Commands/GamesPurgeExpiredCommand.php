<?php

namespace App\Console\Commands;

use App\Actions\Analytics\MarkAnalyticsDayAction;
use App\Actions\Analytics\MarkAnalyticsPeriodHourAction;
use App\Enums\GameEventType;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\TrafficVisit;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes what playing games recorded once it is past its retention, and
 * nothing else: game events that occurred more than
 * games.retention_days.events ago, and emails left in a game more than
 * games.retention_days.email_captures ago. Attempts, answers and results stay:
 * they are what a player's result is. Analytics counts the share clicks of
 * the attempts started on each visit, so the days of the visits whose
 * attempts' share clicks go are marked to be read again.
 *
 * In small batches, each a short DELETE by primary key, so a large backlog
 * never holds one long lock. A result seen and an email are steps of their
 * period's funnel: each batch tells it the hours of the assignments whose
 * results seen or emails go (MarkAnalyticsPeriodHourAction), in the batch's
 * transaction. The cutoffs are fixed when the run starts; a run
 * interrupted part way, or run twice, simply deletes what is still older. A
 * retention that is not a positive number of days fails the run without
 * deleting anything.
 */
final class GamesPurgeExpiredCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 10_000;

    protected $signature = 'games:purge-expired
        {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Delete game events and emails left in games that are older than their configured retention.';

    public function handle(MarkAnalyticsPeriodHourAction $periodHours): int
    {
        $events = $this->retention('events');
        $emails = $this->retention('email_captures');

        if ($events === null || $emails === null) {
            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_CHUNK_SIZE]]);

        if ($chunk === false) {
            $this->error('The --chunk option must be a whole number from 1 to '.self::MAX_CHUNK_SIZE.'.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        // The days of the visits whose attempts' share clicks go: Analytics keeps them on each visit's row.
        $shareDays = TrafficVisit::query()
            ->whereIn('id', GameAttempt::query()
                ->whereNotNull('traffic_visit_id')
                ->whereIn('id', GameEvent::query()->where('type', GameEventType::ShareClicked->value)->where('occurred_at', '<', $now->subDays($events))->select('attempt_id'))
                ->select('traffic_visit_id'))
            ->distinct()
            ->pluck('network_day')
            ->map(fn (mixed $day): string => substr((string) $day, 0, 10))
            ->all();
        $deletedEvents = $this->purge(GameEvent::query()->where('occurred_at', '<', $now->subDays($events)), $chunk, fn (array $ids) => $periodHours->ofAssignments(
            GameAssignment::query()->whereIn('id', GameAttempt::query()->select('assignment_id')->whereIn('id', GameEvent::query()->select('attempt_id')->whereKey($ids)->where('type', GameEventType::ResultViewed->value))),
        ));
        $deletedEmails = $this->purge(GameEmailCapture::query()->where('captured_at', '<', $now->subDays($emails)), $chunk, fn (array $ids) => $periodHours->ofAssignments(
            GameAssignment::query()->whereIn('id', GameAttempt::query()->select('assignment_id')->whereIn('id', GameEmailCapture::query()->select('attempt_id')->whereKey($ids))),
        ));

        foreach ($shareDays as $day) {
            app(MarkAnalyticsDayAction::class)->handle($day, $now);
        }

        $this->info("Deleted {$deletedEvents} game events older than {$events} days.");
        $this->info("Deleted {$deletedEmails} emails left in games more than {$emails} days ago.");

        return self::SUCCESS;
    }

    /** A retention in days, or null — said, and nothing deleted — when it is not a positive number of days. */
    private function retention(string $key): ?int
    {
        $days = config("games.retention_days.{$key}");

        if (is_int($days) && $days >= 1) {
            return $days;
        }

        $this->error("games.retention_days.{$key} must be a positive number of days; nothing was deleted.");

        return null;
    }

    /**
     * @param  Builder<GameEvent>|Builder<GameEmailCapture>  $expired
     * @param  Closure(list<int>): void  $telling  what is told of a batch's rows before they go
     */
    private function purge(Builder $expired, int $chunk, Closure $telling): int
    {
        $deleted = 0;

        do {
            /** @var list<int> $ids */
            $ids = (clone $expired)->orderBy('id')->limit($chunk)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            if ($ids !== []) {
                $deleted += DB::transaction(function () use ($expired, $ids, $telling): int {
                    $telling($ids);

                    return $expired->getModel()->newQuery()->whereKey($ids)->delete();
                });
            }
        } while (count($ids) === $chunk);

        return $deleted;
    }
}
