<?php

namespace App\Console\Commands;

use App\Models\TranslationAiProviderCall;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Deletes Translation AI telemetry — rows of translation_ai_provider_calls —
 * that completed more than translation.telemetry.retention_days ago, and
 * nothing else: no translation, no generated draft, no log.
 *
 * In small batches, each a short DELETE by primary key, so a large backlog
 * never holds one long lock. The cutoff is fixed when the run starts; a run
 * interrupted part way, or run twice, simply deletes what is still older. A
 * retention that is not a positive number of days fails the run without
 * deleting anything.
 */
final class TranslationsPurgeAiCallsCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 10_000;

    protected $signature = 'translations:purge-ai-calls
        {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Delete Translation AI call telemetry older than the configured retention.';

    public function handle(): int
    {
        $days = config('translation.telemetry.retention_days');

        if (! is_int($days) || $days < 1) {
            $this->error('translation.telemetry.retention_days must be a positive number of days; nothing was deleted.');

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_CHUNK_SIZE]]);

        if ($chunk === false) {
            $this->error('The --chunk option must be a whole number from 1 to '.self::MAX_CHUNK_SIZE.'.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $deleted = 0;

        do {
            $ids = TranslationAiProviderCall::query()
                ->where('completed_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += TranslationAiProviderCall::query()->whereKey($ids)->delete();
            }
        } while (count($ids) === $chunk);

        $this->info("Deleted {$deleted} Translation AI call records older than {$days} days.");

        return self::SUCCESS;
    }
}
