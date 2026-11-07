<?php

namespace App\Console\Commands;

use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecalculateHotScoresCommand extends Command
{
    private const MAX_CHUNK_SIZE = 1000;

    protected $signature = 'posts:recalculate-hot-scores {--chunk=500}';

    protected $description = 'Recalculate hot_score for all posts.';

    public function handle(RecalculatePostScoreAction $recalculatePostScore): int
    {
        $count = 0;
        $failures = 0;
        $chunkSize = $this->chunkSize();
        $total = Post::query()->count();
        $progress = $total > 0 ? $this->output->createProgressBar($total) : null;

        $progress?->start();

        Post::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($posts) use (&$count, &$failures, $progress, $recalculatePostScore): void {
                foreach ($posts as $post) {
                    try {
                        // Under the post's lock, as every vote and comment
                        // recalculates it: a sweep must never write a score
                        // computed from counters a vote has just changed.
                        // A post deleted since the chunk was read is skipped,
                        // and not counted as recalculated.
                        $recalculated = DB::transaction(function () use ($post, $recalculatePostScore): bool {
                            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->first();

                            if ($locked === null) {
                                return false;
                            }

                            $recalculatePostScore->handle($locked);

                            return true;
                        });

                        if ($recalculated) {
                            $count++;
                        }
                    } catch (Throwable $exception) {
                        $failures++;

                        Log::error('Failed to recalculate post hot score.', [
                            'post_id' => $post->id,
                            'exception' => $exception,
                        ]);

                        $this->error("Failed to recalculate hot score for post {$post->id}: {$exception->getMessage()}");
                    } finally {
                        $progress?->advance();
                    }
                }
            });

        if ($progress !== null) {
            $progress->finish();
            $this->newLine();
        }

        $this->info("Recalculated hot scores for {$count} posts.");

        if ($failures > 0) {
            $this->error("Failed to recalculate hot scores for {$failures} posts.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function chunkSize(): int
    {
        $rawChunkSize = $this->option('chunk');
        $chunkSize = filter_var($rawChunkSize, FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1) {
            $this->warn('Invalid chunk size provided; using 1.');

            return 1;
        }

        if ($chunkSize > self::MAX_CHUNK_SIZE) {
            $this->warn('Chunk size exceeds maximum; using '.self::MAX_CHUNK_SIZE.'.');

            return self::MAX_CHUNK_SIZE;
        }

        return $chunkSize;
    }
}
