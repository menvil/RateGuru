<?php

namespace App\Console\Commands;

use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes what playing games recorded once it is past its retention, and
 * nothing else: game events that occurred more than
 * games.retention_days.events ago, and emails left in a game more than
 * games.retention_days.email_captures ago. Attempts, answers and results stay:
 * they are what a player's result is.
 *
 * In small batches, each a short DELETE by primary key, so a large backlog
 * never holds one long lock. The cutoffs are fixed when the run starts; a run
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

    public function handle(): int
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
        $deletedEvents = $this->purge(GameEvent::query()->where('occurred_at', '<', $now->subDays($events)), $chunk);
        $deletedEmails = $this->purge(GameEmailCapture::query()->where('captured_at', '<', $now->subDays($emails)), $chunk);

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
     */
    private function purge(Builder $expired, int $chunk): int
    {
        $deleted = 0;

        do {
            $ids = (clone $expired)->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += $expired->getModel()->newQuery()->whereKey($ids)->delete();
            }
        } while (count($ids) === $chunk);

        return $deleted;
    }
}
