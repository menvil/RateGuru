<?php

namespace App\Console\Commands;

use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Models\Post;
use Illuminate\Console\Command;

final class RecalculateHotScoresCommand extends Command
{
    protected $signature = 'posts:recalculate-hot-scores {--chunk=500}';

    protected $description = 'Recalculate hot_score for all posts.';

    public function handle(RecalculatePostScoreAction $recalculatePostScore): int
    {
        $count = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));

        Post::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($posts) use (&$count, $recalculatePostScore): void {
                foreach ($posts as $post) {
                    $recalculatePostScore->handle($post);
                    $count++;
                }
            });

        $this->info("Recalculated hot scores for {$count} posts.");

        return self::SUCCESS;
    }
}
