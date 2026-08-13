<?php

namespace App\Console\Commands;

use App\Enums\PostPurgeOutcome;
use App\Services\Posts\PostRetentionPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Permanently removes author-deleted posts whose retention window expired,
 * through PostRetentionPurgeService — the only sanctioned force-delete
 * boundary for posts. The candidate query is a pre-filter only: every post
 * is re-read under lock and revalidated inside the service, so two
 * concurrent runs cannot partially destroy a graph (the loser sees
 * already_gone). Physical media is never touched here.
 */
final class PostsPurgeCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    protected $signature = 'posts:purge
        {--post= : Only process the post with this id}
        {--older-than= : Override the configured retention, in days, for this run}
        {--dry-run : Report outcomes without deleting anything}
        {--chunk=200 : Number of posts to load per chunk}';

    protected $description = 'Permanently delete author-deleted posts past their retention window, including their discussion graph, and release their media assets.';

    public function handle(PostRetentionPurgeService $purgeService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $olderThanDays = $this->olderThanDaysOverride();

        if ($olderThanDays === false) {
            return self::FAILURE;
        }

        $chunkSize = $this->chunkSize();

        if ($chunkSize === false) {
            return self::FAILURE;
        }

        $postId = $this->postIdFilter();

        if ($postId === false) {
            return self::FAILURE;
        }

        $counts = array_fill_keys(array_map(
            fn (PostPurgeOutcome $outcome): string => $outcome->value,
            PostPurgeOutcome::cases(),
        ), 0);

        $process = function (int $id) use (&$counts, $purgeService, $olderThanDays, $dryRun): void {
            try {
                $outcome = $purgeService->purge($id, $olderThanDays, $dryRun);
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Post purge failed.', ['post_id' => $id, 'exception' => $exception->getMessage()]);
                $outcome = PostPurgeOutcome::Failed;
            }

            $counts[$outcome->value]++;
            $this->line(sprintf('post %d: %s', $id, $outcome->value));
        };

        if ($postId !== null) {
            // Targeted mode runs the service even when the row is not a
            // candidate so the operator sees the precise outcome
            // (already_gone / invalid_state / not_expired / …).
            $process($postId);
        } else {
            $purgeService->candidates($olderThanDays)
                ->orderBy('id')
                ->chunkById($chunkSize, function ($posts) use ($process): void {
                    foreach ($posts as $post) {
                        $process((int) $post->getKey());
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

        return $counts[PostPurgeOutcome::Failed->value] > 0 ? self::FAILURE : self::SUCCESS;
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

    private function postIdFilter(): int|null|false
    {
        $raw = $this->option('post');

        if ($raw === null) {
            return null;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('The --post option must be a positive integer id.');

            return false;
        }

        return (int) $raw;
    }
}
