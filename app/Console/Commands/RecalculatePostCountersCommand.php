<?php

namespace App\Console\Commands;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Models\Post;
use Illuminate\Console\Command;

class RecalculatePostCountersCommand extends Command
{
    protected $signature = 'rateguru:recalculate-post-counters {--post-id=}';

    protected $description = 'Recalculate vote counters for posts.';

    public function handle(RecalculatePostCountersAction $action): int
    {
        $postId = $this->option('post-id');

        $query = Post::query();

        if ($postId) {
            $query->whereKey($postId);
        }

        $count = 0;

        $query->chunkById(100, function ($posts) use ($action, &$count) {
            foreach ($posts as $post) {
                $action->handle($post);
                $count++;
            }
        });

        $this->info("Recalculated counters for {$count} posts.");

        return self::SUCCESS;
    }
}
