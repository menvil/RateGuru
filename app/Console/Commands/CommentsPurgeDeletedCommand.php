<?php

namespace App\Console\Commands;

use App\Enums\CommentPurgeOutcome;
use App\Services\Comments\CommentRetentionPurgeService;
use App\Support\ContentLifecycle\CommentRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Daily cleanup of purely author-deleted leaf comments past their
 * retention window, through CommentRetentionPurgeService — the candidate
 * query is a pre-filter only; every comment is re-read under lock
 * (Post -> Comment) and revalidated, so structural anchors, parent-post
 * holds, moderation evidence and open reports always win over the clock.
 * Output carries ids and outcome counts only — never bodies or PII.
 */
final class CommentsPurgeDeletedCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    protected $signature = 'comments:purge-deleted
        {--comment= : Only process the comment with this id}
        {--older-than= : Override the configured retention, in days, for this run}
        {--dry-run : Report outcomes without deleting anything}
        {--chunk=200 : Number of comments to load per chunk}
        {--force : Required to destructively run an --older-than override shorter than the configured retention}';

    protected $description = 'Physically delete author-deleted leaf comments past their retention window, sweeping their votes and processed reports.';

    public function handle(CommentRetentionPurgeService $purgeService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $olderThanDays = $this->olderThanDaysOverride();

        if ($olderThanDays === false) {
            return self::FAILURE;
        }

        // Resolve the configured retention up front: bad config stops
        // the whole run before any candidate query (fail closed).
        try {
            $configuredDays = CommentRetention::authorDeleteDays();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // A destructive override shorter than the configured window needs
        // explicit acknowledgement; dry-run is always allowed without it.
        if ($olderThanDays !== null && $olderThanDays < $configuredDays && ! $dryRun && ! $this->option('force')) {
            $this->error('A destructive --older-than override shorter than the configured retention requires --force (dry-run is allowed without it).');

            return self::FAILURE;
        }

        $olderThanDays ??= $configuredDays;

        $chunkSize = $this->chunkSize();

        if ($chunkSize === false) {
            return self::FAILURE;
        }

        $commentId = $this->commentIdFilter();

        if ($commentId === false) {
            return self::FAILURE;
        }

        $counts = array_fill_keys(array_map(
            fn (CommentPurgeOutcome $outcome): string => $outcome->value,
            CommentPurgeOutcome::cases(),
        ), 0);

        $process = function (int $id) use (&$counts, $purgeService, $olderThanDays, $dryRun): void {
            try {
                $outcome = $purgeService->purge($id, $olderThanDays, $dryRun);
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Comment purge failed.', ['comment_id' => $id, 'exception' => $exception::class]);
                $outcome = CommentPurgeOutcome::Failed;
            }

            $counts[$outcome->value]++;
            $this->line(sprintf('comment %d: %s', $id, $outcome->value));
        };

        if ($commentId !== null) {
            $process($commentId);
        } else {
            $purgeService->candidates($olderThanDays)
                ->orderBy('id')
                ->chunkById($chunkSize, function ($comments) use ($process): void {
                    foreach ($comments as $comment) {
                        $process((int) $comment->getKey());
                    }
                });
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

        return $counts[CommentPurgeOutcome::Failed->value] > 0 ? self::FAILURE : self::SUCCESS;
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

    private function commentIdFilter(): int|null|false
    {
        $raw = $this->option('comment');

        if ($raw === null) {
            return null;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('The --comment option must be a positive integer id.');

            return false;
        }

        return (int) $raw;
    }
}
