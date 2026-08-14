<?php

namespace App\Console\Commands;

use App\Enums\ModerationPurgeOutcome;
use App\Services\Moderation\ModerationContentPurgeService;
use App\Support\ContentLifecycle\ModerationContentRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Physical cleanup of FINALIZED moderation removals
 * (docs/architecture/moderation-content-lifecycle.md). Disabled by
 * default: with no configured MODERATION_CONTENT_RETENTION_DAYS and no
 * explicit --older-than override the scheduled run is a cheap, safe
 * no-op. A manual --older-than override while retention is disabled may
 * dry-run freely, but destructive execution additionally requires
 * --force — which only acknowledges the override and can NEVER bypass
 * finalization state, cutoffs, evidence holds, structural anchors or
 * parent-post holds. Reversible Hidden content is never purge material.
 * Output carries ids and outcome counts only — never bodies, reasons or
 * PII.
 */
final class ModerationPurgeContentCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    protected $signature = 'moderation:purge-content
        {--type=all : Which finalized targets to process: post, comment or all}
        {--id= : Only process the target with this id (requires --type=post or --type=comment)}
        {--older-than= : Override the configured retention, in days, for this run}
        {--dry-run : Report outcomes without deleting anything}
        {--chunk=200 : Number of rows to load per chunk}
        {--force : Required to destructively run a manual --older-than override while retention is disabled}';

    protected $description = 'Physically delete finalized moderation removals past the configured retention, respecting every evidence and structural hold.';

    public function handle(ModerationContentPurgeService $purgeService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $type = (string) $this->option('type');

        if (! in_array($type, ['post', 'comment', 'all'], true)) {
            $this->error('The --type option must be post, comment or all.');

            return self::FAILURE;
        }

        $olderThanDays = $this->olderThanDaysOverride();

        if ($olderThanDays === false) {
            return self::FAILURE;
        }

        try {
            $configuredDays = ModerationContentRetention::days();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($configuredDays === null && $olderThanDays === null) {
            $this->info('Moderation content retention is disabled; nothing to purge. Set MODERATION_CONTENT_RETENTION_DAYS or pass --older-than to enable.');

            return self::SUCCESS;
        }

        if ($configuredDays === null && $olderThanDays !== null && ! $dryRun && ! $this->option('force')) {
            $this->error('Moderation retention is disabled: a destructive manual --older-than override requires --force (dry-run is allowed without it).');

            return self::FAILURE;
        }

        $effectiveDays = $olderThanDays ?? $configuredDays;

        $chunkSize = $this->chunkSize();

        if ($chunkSize === false) {
            return self::FAILURE;
        }

        $targetId = $this->targetIdFilter($type);

        if ($targetId === false) {
            return self::FAILURE;
        }

        $counts = array_fill_keys(array_map(
            fn (ModerationPurgeOutcome $outcome): string => $outcome->value,
            ModerationPurgeOutcome::cases(),
        ), 0);

        $process = function (string $kind, int $id) use (&$counts, $purgeService, $effectiveDays, $dryRun): void {
            try {
                $outcome = $kind === 'post'
                    ? $purgeService->purgePost($id, $effectiveDays, $dryRun)
                    : $purgeService->purgeComment($id, $effectiveDays, $dryRun);
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Moderation content purge failed.', [
                    'target_type' => $kind,
                    'target_id' => $id,
                    'exception' => $exception::class,
                ]);
                $outcome = ModerationPurgeOutcome::Failed;
            }

            $counts[$outcome->value]++;
            $this->line(sprintf('%s %d: %s', $kind, $id, $outcome->value));
        };

        if ($targetId !== null) {
            $process($type, $targetId);
        } else {
            if ($type === 'post' || $type === 'all') {
                $purgeService->postCandidates($effectiveDays)
                    ->orderBy('id')
                    ->chunkById($chunkSize, function ($posts) use ($process): void {
                        foreach ($posts as $post) {
                            $process('post', (int) $post->getKey());
                        }
                    });
            }

            if ($type === 'comment' || $type === 'all') {
                $purgeService->commentCandidates($effectiveDays)
                    ->orderBy('id')
                    ->chunkById($chunkSize, function ($comments) use ($process): void {
                        foreach ($comments as $comment) {
                            $process('comment', (int) $comment->getKey());
                        }
                    });
            }
        }

        $this->newLine();

        foreach ($counts as $outcome => $count) {
            if ($count > 0) {
                $this->info(sprintf('%s: %d', $outcome, $count));
            }
        }

        if (array_sum($counts) === 0) {
            $this->info('No purge candidates.');
        }

        return $counts[ModerationPurgeOutcome::Failed->value] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function olderThanDaysOverride(): int|null|false
    {
        $raw = $this->option('older-than');

        if ($raw === null) {
            return null;
        }

        if (! ctype_digit((string) $raw)) {
            $this->error('The --older-than option must be a non-negative integer number of days.');

            return false;
        }

        return (int) $raw;
    }

    private function chunkSize(): int|false
    {
        $raw = (string) $this->option('chunk');

        if (! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > self::MAX_CHUNK_SIZE) {
            $this->error(sprintf('The --chunk option must be an integer between 1 and %d.', self::MAX_CHUNK_SIZE));

            return false;
        }

        return (int) $raw;
    }

    private function targetIdFilter(string $type): int|null|false
    {
        $raw = $this->option('id');

        if ($raw === null) {
            return null;
        }

        if ($type === 'all') {
            $this->error('The --id option requires --type=post or --type=comment.');

            return false;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('The --id option must be a positive integer id.');

            return false;
        }

        return (int) $raw;
    }
}
